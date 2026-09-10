<?php
declare(strict_types=1);

namespace LexMcp;

final class Application
{
    private const DISCOVERY_RESOURCE = '/.well-known/oauth-protected-resource';
    private const DISCOVERY_SERVER = '/.well-known/oauth-authorization-server';
    private const DISCOVERY_COMBINED = '/oauth/discovery';

    /**
     * MCP clients fall back to these issuer-root endpoints whenever authorization
     * server metadata cannot be fetched, so both spellings reach the same handler.
     */
    private const ROUTE_ALIASES = [
        '/authorize' => '/oauth/authorize',
        '/token' => '/oauth/token',
        '/register' => '/oauth/register',
        '/revoke' => '/oauth/revoke',
        '/.well-known/oauth-protected-resource/mcp' => self::DISCOVERY_RESOURCE,
        '/.well-known/oauth-authorization-server/mcp' => self::DISCOVERY_SERVER,
        '/.well-known/openid-configuration' => self::DISCOVERY_SERVER,
        '/.well-known/openid-configuration/mcp' => self::DISCOVERY_SERVER,
        '/oauth/protected-resource' => self::DISCOVERY_RESOURCE,
        '/oauth/authorization-server' => self::DISCOVERY_SERVER,
    ];

    public static function run(\PDO $pdo): void
    {
        $traceId = Util::uuid();
        $path = '';
        try {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('SET NAMES utf8mb4');
            Migrator::applyPending($pdo);
            $oauth = new OAuth($pdo);
            $accounts = new AccountStore($pdo);
            $limiter = new RateLimiter($pdo);
            $client = new LexwareClient($limiter);
            $aliases = new AliasResolver(Config::aliasFile());
            $validator = new Validator($aliases);
            $idempotency = new IdempotencyStore($pdo);
            $tools = new ToolRouter($accounts, $aliases, $validator, $client, $idempotency, $pdo);
            $content = new ContentRegistry($accounts);

            $path = self::route(self::requestPath());
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            self::securityHeaders();

            // Hosting layers frequently deny every dot path before mod_rewrite runs.
            // The 403 handler routes such a request back into the front controller,
            // where only the public discovery documents are served.
            if (self::deniedBeforeRouting() && !self::isDiscoveryPath($path)) {
                throw new AppError('forbidden', 'Access to this path is denied.', 403);
            }

            if ($path === '/mcp') {
                (new McpServer($oauth, $tools, $content))->handle();
                return;
            }
            if (preg_match('#^/uploads/([a-f0-9-]{36})$#D', $path, $uploadMatch)) {
                if ($method !== 'PUT') throw new AppError('method_not_allowed', 'Upload endpoint requires PUT.', 405);
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > UploadStore::MAX_BYTES) throw new AppError('file_size_invalid', 'Maximum file size is 4500000 bytes (4.5 MB).', 413);
                $input = fopen('php://input', 'rb');
                if ($input === false) throw new AppError('invalid_file', 'Cannot read upload body.', 400);
                try {
                    self::json((new UploadStore($pdo))->receive($uploadMatch[1], Util::header('X-Upload-Token') ?? '', $input));
                } finally { fclose($input); }
                return;
            }
            if ($path === self::DISCOVERY_RESOURCE) {
                self::json($oauth->protectedResourceMetadata());
                return;
            }
            if ($path === self::DISCOVERY_SERVER) {
                self::json($oauth->authorizationServerMetadata());
                return;
            }
            if ($path === self::DISCOVERY_COMBINED) {
                self::json($oauth->discoveryMetadata());
                return;
            }
            if ($path === '/oauth/register' && $method === 'POST') {
                self::json($oauth->register(self::jsonBody()), 201);
                return;
            }
            if ($path === '/oauth/token' && $method === 'POST') {
                self::requireForm();
                self::json($oauth->token($_POST));
                return;
            }
            if ($path === '/oauth/revoke' && $method === 'POST') {
                self::requireForm();
                $oauth->revoke($_POST);
                http_response_code(200);
                return;
            }
            if ($path === '/oauth/authorize') {
                if ($method === 'GET') {
                    $oauth->beginAuthorization($_GET);
                } elseif ($method === 'POST') {
                    self::requireForm();
                    $oauth->continueAuthorization($_POST);
                } else {
                    throw new AppError('method_not_allowed', 'Method not allowed.', 405);
                }
                return;
            }
            if ($path === '/accounts') {
                self::accounts($method, $oauth, $accounts, $client, new UserSettings($pdo));
                return;
            }
            if ($path === '/' && $method === 'GET') {
                $oauth->html('Lexware MCP', '<h1>Lexware Office MCP</h1><p>Produktionsserver für die Lexware Public API.</p><p><a href="/accounts">Lexware-Accounts verwalten</a></p>');
                return;
            }
            if ($path === '/docs' && $method === 'GET') {
                $oauth->html('Lexware MCP Dokumentation', '<h1>Lexware MCP Dokumentation</h1><p>Die agentenlesbaren Handbooks werden nach OAuth über MCP Resources bereitgestellt. Einstiegspunkte sind <code>lexware://handbooks/tool-selection</code>, <code>lexware://handbooks/accounts</code> und <code>lexware://handbooks/incoming-vouchers</code>.</p><p><a href="/accounts">Accounts verwalten</a></p>');
                return;
            }
            throw new AppError('not_found', 'Route not found.', 404);
        } catch (AppError $e) {
            $safePath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
            SafeLogger::log('http_error', ['trace_id' => $traceId, 'operation' => $safePath, 'http_status' => $e->httpStatus, 'error_code' => $e->errorCode]);
            if (self::wantsHtml($path, Util::header('Accept'))) {
                self::htmlError($e, $traceId);
                return;
            }
            self::json(['error' => $e->errorCode, 'message' => $e->getMessage(), 'trace_id' => $traceId], $e->httpStatus);
        } catch (\Throwable $e) {
            SafeLogger::log('http_error', ['trace_id' => $traceId, 'http_status' => 500, 'error_code' => 'internal_error']);
            self::json(['error' => 'internal_error', 'message' => 'Internal server error.', 'trace_id' => $traceId], 500);
        }
    }

    /** Browser routes answer a person, not a client library. */
    private static function wantsHtml(string $route, ?string $accept): bool
    {
        if (!in_array($route, ['/oauth/authorize', '/accounts'], true)) {
            return false;
        }
        $accept = strtolower((string) $accept);
        return $accept === '' || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private static function htmlError(AppError $e, string $traceId): void
    {
        self::status($e->httpStatus);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Autorisierung nicht möglich</title>'
            . '<style>body{font:16px system-ui;max-width:720px;margin:3rem auto;padding:0 1rem;color:#18212b}code{font-size:.85em;color:#5b6774}</style><body>'
            . '<h1>Autorisierung nicht möglich</h1><p>' . OAuth::h($e->getMessage()) . '</p>'
            . '<p>Bitte die Verbindung im MCP-Client neu starten. Ein Autorisierungsvorgang gilt 30 Minuten und kann nur einmal bestätigt werden.</p>'
            . '<p><code>' . OAuth::h($e->errorCode) . ' · ' . OAuth::h($traceId) . '</code></p></body></html>';
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        // An ErrorDocument handler is reached with the front controller in
        // REQUEST_URI; the denied path is then only available in REDIRECT_URL.
        $redirected = $_SERVER['REDIRECT_URL'] ?? null;
        if (str_ends_with($path, '/index.php') && is_string($redirected) && $redirected !== '') {
            $path = $redirected;
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function route(string $path): string
    {
        return self::ROUTE_ALIASES[$path] ?? $path;
    }

    private static function isDiscoveryPath(string $route): bool
    {
        return $route === self::DISCOVERY_RESOURCE || $route === self::DISCOVERY_SERVER || $route === self::DISCOVERY_COMBINED;
    }

    private static function deniedBeforeRouting(): bool
    {
        $status = $_SERVER['REDIRECT_STATUS'] ?? null;
        return is_scalar($status) && (int) $status === 403;
    }

    private static function accounts(string $method, OAuth $oauth, AccountStore $accounts, LexwareClient $client, UserSettings $settings): void
    {
        $user = $oauth->currentWebUser();
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'login') {
            self::requireForm();
            $oauth->loginForAccounts($_POST);
            header('Location: /accounts', true, 303);
            return;
        }
        if ($user === null) {
            if ($method !== 'GET') {
                throw new AppError('login_required', 'Login is required.', 401);
            }
            $oauth->renderLogin();
            return;
        }
        if ($method === 'POST') {
            self::requireForm();
            $oauth->assertCsrf($_POST);
            $action = $_POST['action'] ?? '';
            if ($action === 'update_user_settings') {
                $settings->setFinalizeEnabled($user, ($_POST['finalize_enabled'] ?? '') === '1');
                header('Location: /accounts?settings_saved=1', true, 303);
                return;
            }
            if ($action === 'save') {
                $alias = Util::requireString($_POST, 'alias', 96);
                $apiKey = Util::requireString($_POST, 'api_key', 512);
                $temporary = ['api_key_fingerprint' => Crypto::fingerprint($apiKey)];
                try {
                    $profile = $client->request($temporary, $apiKey, 'GET', '/v1/profile')->data;
                    if (!is_array($profile)) {
                        throw new AppError('invalid_api_key', 'Lexware profile could not be loaded.', 400);
                    }
                    $accounts->save($user, $alias, $apiKey, is_string($profile['organizationId'] ?? null) ? $profile['organizationId'] : null, self::profileName($profile));
                } finally {
                    sodium_memzero($apiKey);
                }
                header('Location: /accounts?saved=1', true, 303);
                return;
            }
            if ($action === 'deactivate') {
                $accounts->setActive($user, Util::requireString($_POST, 'alias', 96), false);
                header('Location: /accounts', true, 303);
                return;
            }
            if ($action === 'update_connection') {
                $scopes = $_POST['scopes'] ?? [];
                if (!is_array($scopes)) {
                    throw new AppError('validation_error', 'Permissions must be submitted as a list.', 400);
                }
                $oauth->updateConnection(
                    $user,
                    Util::requireString($_POST, 'connection_id', 36),
                    Util::requireString($_POST, 'connection_name', 96),
                    $scopes,
                );
                header('Location: /accounts?connection_saved=1', true, 303);
                return;
            }
            throw new AppError('invalid_request', 'Unknown account action.', 400);
        }
        if ($method !== 'GET') {
            throw new AppError('method_not_allowed', 'Method not allowed.', 405);
        }
        $rows = '';
        $csrf = OAuth::h($oauth->csrfToken());
        $finalizeChecked = $settings->finalizeEnabled($user) ? ' checked' : '';
        $settingsForm = '<h2>Benutzereinstellungen</h2><form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="update_user_settings">'
            . '<label class="permission"><input type="checkbox" name="finalize_enabled" value="1"' . $finalizeChecked . '> Belege finalisieren und verbuchen erlauben</label>'
            . '<p>Gilt für alle Ihre Verbindungen und Lexware-Accounts. Die einzelne Verbindung benötigt zusätzlich die Berechtigung zum Finalisieren. Ausschalten sperrt neue Buchungsaufrufe sofort.</p><button>Einstellung speichern</button></form>';
        foreach ($accounts->listForUser($user) as $account) {
            $rows .= '<tr><td>' . OAuth::h((string) $account['alias']) . '</td><td>' . OAuth::h((string) ($account['organization_name'] ?? '')) . '</td><td>' . ((int) $account['active'] === 1 ? 'aktiv' : 'inaktiv') . '</td><td><form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="alias" value="' . OAuth::h((string) $account['alias']) . '"><button>Deaktivieren</button></form></td></tr>';
        }
        $connections = '';
        $scopeLabels = [
            'accounts:read' => 'Lexware-Accounts auflisten',
            'lexware:read' => 'Lexware-Daten lesen',
            'lexware:write' => 'Entwürfe erstellen und ändern / Dateien hochladen',
            'lexware:finalize' => 'Belege finalisieren oder verbuchen',
            'lexware:delete' => 'Daten löschen',
            'content:read' => 'Anleitungen und MCP-Inhalte lesen',
        ];
        foreach ($oauth->listConnections($user) as $connection) {
            $allowed = is_array($connection['allowed_scopes'] ?? null) ? $connection['allowed_scopes'] : [];
            $requested = is_array($connection['requested_scopes'] ?? null) ? $connection['requested_scopes'] : [];
            $checks = '';
            foreach ($scopeLabels as $scope => $label) {
                $checked = in_array($scope, $allowed, true) ? ' checked' : '';
                $checks .= '<label class="permission"><input type="checkbox" name="scopes[]" value="' . OAuth::h($scope) . '"' . $checked . '> ' . OAuth::h($label) . '</label>';
            }
            $technicalName = (string) ($connection['client_name'] ?: $connection['client_id']);
            $requestedText = implode(', ', array_map(static fn(string $scope): string => $scopeLabels[$scope] ?? $scope, $requested));
            $connections .= '<form method="post" class="connection"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="update_connection"><input type="hidden" name="connection_id" value="' . OAuth::h((string) $connection['id']) . '">'
                . '<label>Anzeigename<input name="connection_name" required maxlength="96" value="' . OAuth::h((string) $connection['name']) . '"></label>'
                . '<p class="muted">Client: ' . OAuth::h($technicalName) . '<br>Ursprünglich angefragt: ' . OAuth::h($requestedText) . '</p><fieldset><legend>Berechtigungen</legend>' . $checks . '</fieldset><button>Verbindung speichern</button></form>';
        }
        if ($connections === '') {
            $connections = '<p>Noch keine MCP-Verbindung vorhanden. Sie erscheint hier nach der ersten OAuth-Freigabe.</p>';
        }
        $body = '<h1>Lexware MCP verwalten</h1>' . $settingsForm . '<h2>Verbundene Harnesse</h2><p>Diese Freigaben gelten sofort. Nicht freigegebene Tools werden dem jeweiligen Harness nicht mehr in <code>tools/list</code> angeboten.</p>' . $connections
            . '<h2>Lexware-Accounts</h2><table><tr><th>Alias</th><th>Organisation</th><th>Status</th><th></th></tr>' . $rows . '</table><h2>API-Key hinterlegen oder ersetzen</h2><form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="save"><label>Account-Alias<input name="alias" required maxlength="96"></label><label>Lexware API-Key<input type="password" name="api_key" required autocomplete="off"></label><button>Speichern und prüfen</button></form><p>Der API-Key wird nach dem Speichern nicht wieder angezeigt.</p>';
        $oauth->html('Lexware-Accounts', $body);
    }

    private static function profileName(array $profile): ?string
    {
        foreach (['companyName','organizationName','name'] as $key) {
            if (is_string($profile[$key] ?? null)) return $profile[$key];
        }
        return null;
    }

    private static function jsonBody(): array
    {
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (!is_string($body) || strlen($body) > 1048576) {
            throw new AppError('request_too_large', 'Request body is too large.', 413);
        }
        return Util::jsonDecode($body);
    }

    private static function requireForm(): void
    {
        $type = strtolower((string) Util::header('Content-Type'));
        if (!str_starts_with($type, 'application/x-www-form-urlencoded')) {
            throw new AppError('unsupported_media_type', 'Form endpoint requires application/x-www-form-urlencoded.', 415);
        }
    }

    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (Config::production()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    private static function json(array $body, int $status = 200): void
    {
        self::status($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo Util::jsonEncode($body);
    }

    /**
     * An ErrorDocument response keeps the status Apache picked unless the handler
     * states its own. Under CGI and FastCGI only a full status line is forwarded,
     * so a rescued discovery document would otherwise stay the 403 that makes MCP
     * clients abort their authorization server lookup.
     */
    private static function status(int $status): void
    {
        $reasons = [
            200 => 'OK', 201 => 'Created', 400 => 'Bad Request', 401 => 'Unauthorized',
            403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 500 => 'Internal Server Error',
        ];
        http_response_code($status);
        header('HTTP/1.1 ' . $status . ' ' . ($reasons[$status] ?? 'Status'), true, $status);
    }
}
