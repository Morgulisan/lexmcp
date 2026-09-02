<?php
declare(strict_types=1);

namespace LexMcp;

final class Application
{
    public static function run(\PDO $pdo): void
    {
        $traceId = Util::uuid();
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

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            self::securityHeaders();

            if ($path === '/mcp') {
                (new McpServer($oauth, $tools, $content))->handle();
                return;
            }
            if ($path === '/.well-known/oauth-protected-resource') {
                self::json($oauth->protectedResourceMetadata());
                return;
            }
            if ($path === '/.well-known/oauth-authorization-server') {
                self::json($oauth->authorizationServerMetadata());
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
                self::accounts($method, $oauth, $accounts, $client);
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
            self::json(['error' => $e->errorCode, 'message' => $e->getMessage(), 'trace_id' => $traceId], $e->httpStatus);
        } catch (\Throwable $e) {
            SafeLogger::log('http_error', ['trace_id' => $traceId, 'http_status' => 500, 'error_code' => 'internal_error']);
            self::json(['error' => 'internal_error', 'message' => 'Internal server error.', 'trace_id' => $traceId], 500);
        }
    }

    private static function accounts(string $method, OAuth $oauth, AccountStore $accounts, LexwareClient $client): void
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
            throw new AppError('invalid_request', 'Unknown account action.', 400);
        }
        if ($method !== 'GET') {
            throw new AppError('method_not_allowed', 'Method not allowed.', 405);
        }
        $rows = '';
        $csrf = OAuth::h($oauth->csrfToken());
        foreach ($accounts->listForUser($user) as $account) {
            $rows .= '<tr><td>' . OAuth::h((string) $account['alias']) . '</td><td>' . OAuth::h((string) ($account['organization_name'] ?? '')) . '</td><td>' . ((int) $account['active'] === 1 ? 'aktiv' : 'inaktiv') . '</td><td><form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="alias" value="' . OAuth::h((string) $account['alias']) . '"><button>Deaktivieren</button></form></td></tr>';
        }
        $body = '<h1>Lexware-Accounts</h1><table><tr><th>Alias</th><th>Organisation</th><th>Status</th><th></th></tr>' . $rows . '</table><h2>API-Key hinterlegen oder ersetzen</h2><form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="save"><label>Account-Alias<input name="alias" required maxlength="96"></label><label>Lexware API-Key<input type="password" name="api_key" required autocomplete="off"></label><button>Speichern und prüfen</button></form><p>Der API-Key wird nach dem Speichern nicht wieder angezeigt.</p>';
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
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo Util::jsonEncode($body);
    }
}
