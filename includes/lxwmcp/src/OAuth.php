<?php
declare(strict_types=1);

namespace LexMcp;

use PDO;

final class OAuth
{
    private const SESSION_COOKIE = 'lxmcp_session';
    private const LOGIN_NONCE_COOKIE = 'lxmcp_login_nonce';

    public function __construct(private readonly PDO $pdo) {}

    public function protectedResourceMetadata(): array
    {
        return [
            'resource' => Config::resourceUrl(),
            'authorization_servers' => [Config::publicUrl()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => Config::scopes(),
            'resource_documentation' => Config::publicUrl() . '/docs',
        ];
    }

    public function authorizationServerMetadata(): array
    {
        $base = Config::publicUrl();
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => Config::scopes(),
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
        ];
    }

    /**
     * One document that satisfies readers of either metadata format. Both specs
     * ignore members they do not know, so a host that denies dot paths can still
     * answer protected-resource and authorization-server lookups from one URL.
     */
    public function discoveryMetadata(): array
    {
        return $this->authorizationServerMetadata() + $this->protectedResourceMetadata();
    }

    public function register(array $input): array
    {
        $name = isset($input['client_name']) && is_string($input['client_name']) ? trim($input['client_name']) : 'MCP client';
        $redirects = $input['redirect_uris'] ?? null;
        if (!is_array($redirects) || $redirects === [] || count($redirects) > 10) {
            throw new AppError('invalid_client_metadata', 'redirect_uris must be a non-empty array.', 400);
        }
        foreach ($redirects as $uri) {
            $this->assertRedirectUri($uri);
        }
        if (($input['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new AppError('invalid_client_metadata', 'Only public PKCE clients are supported.', 400);
        }
        if (isset($input['grant_types'])) {
            $grants = $input['grant_types'];
            if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== [] || !in_array('authorization_code', $grants, true)) {
                throw new AppError('invalid_client_metadata', 'grant_types may contain authorization_code and refresh_token.', 400);
            }
        }
        if (isset($input['response_types'])) {
            $responses = $input['response_types'];
            if (!is_array($responses) || $responses === [] || array_diff($responses, ['code']) !== []) {
                throw new AppError('invalid_client_metadata', 'response_types must contain only code.', 400);
            }
        }
        $applicationType = $input['application_type'] ?? 'native';
        if (!in_array($applicationType, ['native', 'web'], true)) {
            throw new AppError('invalid_client_metadata', 'application_type must be native or web.', 400);
        }
        if (isset($input['scope']) && is_string($input['scope'])) {
            $this->parseScopes($input['scope']);
        }
        $clientId = 'lxmcp_' . Util::randomToken(24);
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_oauth_clients(client_id,client_name,redirect_uris) VALUES (?,?,?)');
        $stmt->execute([$clientId, mb_substr($name, 0, 255), Util::jsonEncode(array_values($redirects))]);
        return [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => array_values($redirects),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'application_type' => $applicationType,
        ];
    }

    public function beginAuthorization(array $query): void
    {
        $params = $this->validateAuthorizationParameters($query);
        $requestId = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_oauth_requests(request_id,parameters_json,user_id,expires_at) VALUES (?,?,?,DATE_ADD(NOW(6), INTERVAL 30 MINUTE))');
        $user = $this->currentWebUser();
        $stmt->execute([$requestId, Util::jsonEncode($params), $user]);
        if ($user === null) {
            $this->renderLogin($requestId, '/oauth/authorize');
            return;
        }
        $this->renderConsent($requestId, $params);
    }

    public function continueAuthorization(array $post): void
    {
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';
        $requestId = Util::requireString($post, 'request_id', 64);
        $request = $this->loadAuthorizationRequest($requestId);
        $params = Util::jsonDecode((string) $request['parameters_json']);

        if ($action === 'login') {
            $this->assertLoginNonce($post);
            $userId = $this->authenticateLegacy($post);
            $this->createWebSession($userId);
            // A password entry can take longer than the remaining lifetime, so the
            // consent step starts with a full window of its own.
            $stmt = $this->pdo->prepare('UPDATE lxmcp_oauth_requests SET user_id = ?, expires_at = DATE_ADD(NOW(6), INTERVAL 30 MINUTE) WHERE request_id = ?');
            $stmt->execute([$userId, $requestId]);
            $this->renderConsent($requestId, $params);
            return;
        }

        $userId = $this->requireWebUser();
        $this->assertCsrf($post);
        if ((int) ($request['user_id'] ?? 0) !== $userId) {
            throw new AppError('invalid_request', 'Authorization request is not owned by this session.', 400);
        }
        if ($action === 'deny') {
            $this->deleteAuthorizationRequest($requestId);
            $this->authorizationRedirect($params, ['error' => 'access_denied']);
            return;
        }
        if ($action !== 'approve') {
            throw new AppError('invalid_request', 'Unknown authorization action.', 400);
        }

        $code = Util::randomToken(32);
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_oauth_codes(code_hash,client_id,user_id,redirect_uri,resource,scopes_json,code_challenge,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(6), INTERVAL 5 MINUTE))');
        $stmt->execute([
            Util::tokenHash($code), $params['client_id'], $userId, $params['redirect_uri'],
            $params['resource'], Util::jsonEncode($params['scopes']), $params['code_challenge'],
        ]);
        $this->deleteAuthorizationRequest($requestId);
        $this->authorizationRedirect($params, ['code' => $code]);
    }

    public function token(array $post): array
    {
        $grant = Util::requireString($post, 'grant_type', 64);
        return match ($grant) {
            'authorization_code' => $this->exchangeCode($post),
            'refresh_token' => $this->refresh($post),
            default => throw new AppError('unsupported_grant_type', 'Unsupported OAuth grant type.', 400),
        };
    }

    public function revoke(array $post): void
    {
        $token = Util::requireString($post, 'token', 1024);
        $stmt = $this->pdo->prepare('UPDATE lxmcp_oauth_tokens SET revoked_at = NOW(6) WHERE token_hash = ?');
        $stmt->execute([Util::tokenHash($token)]);
    }

    public function authenticateAccessToken(?string $requiredScope = null): array
    {
        $token = Util::bearerToken();
        if ($token === null) {
            throw new AppError('invalid_token', 'A bearer access token is required.', 401);
        }
        $stmt = $this->pdo->prepare("SELECT user_id,client_id,resource,scopes_json FROM lxmcp_oauth_tokens WHERE token_hash=? AND token_type='access' AND revoked_at IS NULL AND expires_at > NOW(6)");
        $stmt->execute([Util::tokenHash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals(Config::resourceUrl(), (string) $row['resource'])) {
            throw new AppError('invalid_token', 'The access token is invalid, expired, or has the wrong audience.', 401);
        }
        $scopes = json_decode((string) $row['scopes_json'], true);
        if (!is_array($scopes) || ($requiredScope !== null && !in_array($requiredScope, $scopes, true))) {
            throw new AppError('insufficient_scope', 'The access token does not grant the required scope.', 403, false, ['required_scope' => $requiredScope]);
        }
        return ['user_id' => (int) $row['user_id'], 'client_id' => $row['client_id'], 'scopes' => $scopes];
    }

    public function currentWebUser(): ?int
    {
        $token = $_COOKIE[self::SESSION_COOKIE] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM lxmcp_web_sessions WHERE session_hash=? AND expires_at>NOW(6)');
        $stmt->execute([Util::tokenHash($token)]);
        $user = $stmt->fetchColumn();
        if ($user === false) {
            return null;
        }
        $this->pdo->prepare('UPDATE lxmcp_web_sessions SET last_seen_at=NOW(6) WHERE session_hash=?')->execute([Util::tokenHash($token)]);
        return (int) $user;
    }

    public function requireWebUser(): int
    {
        $user = $this->currentWebUser();
        if ($user === null) {
            throw new AppError('login_required', 'Login is required.', 401);
        }
        return $user;
    }

    public function loginForAccounts(array $post): int
    {
        $this->assertLoginNonce($post);
        $user = $this->authenticateLegacy($post);
        $this->createWebSession($user);
        return $user;
    }

    public function renderLogin(string $requestId = '', string $target = '/accounts'): void
    {
        $nonce = Util::randomToken(32);
        setcookie(self::LOGIN_NONCE_COOKIE, $nonce, ['expires' => time() + 600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        $hidden = $requestId !== '' ? '<input type="hidden" name="request_id" value="' . self::h($requestId) . '">' : '';
        $hidden .= '<input type="hidden" name="login_nonce" value="' . self::h($nonce) . '">';
        $this->html('Anmelden', '<h1>Lexware MCP anmelden</h1><p>Einmalige Anmeldung über das bestehende Benutzerkonto.</p><form method="post" action="' . self::h($target) . '">' . $hidden . '<input type="hidden" name="action" value="login"><label>E-Mail<input type="email" name="mail" autocomplete="username"></label><label>Passwort<input type="password" name="password" autocomplete="current-password"></label><p>oder</p><label>Vorhandener MST-Token<input type="password" name="legacy_token" autocomplete="off"></label><button type="submit">Anmelden</button></form>');
    }

    public function csrfToken(): string
    {
        $token = $_COOKIE[self::SESSION_COOKIE] ?? '';
        $stmt = $this->pdo->prepare('SELECT csrf_token FROM lxmcp_web_sessions WHERE session_hash=? AND expires_at>NOW(6)');
        $stmt->execute([Util::tokenHash((string) $token)]);
        $csrf = $stmt->fetchColumn();
        if (!is_string($csrf)) {
            throw new AppError('login_required', 'Login is required.', 401);
        }
        return $csrf;
    }

    public function assertCsrf(array $post): void
    {
        $provided = is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : '';
        if (!hash_equals($this->csrfToken(), $provided)) {
            throw new AppError('csrf_failed', 'The form security token is invalid.', 403);
        }
    }

    private function validateAuthorizationParameters(array $query): array
    {
        // RFC 6749 requires an authorization server to ignore request parameters
        // it does not recognize. Rejecting them breaks MCP clients that add their
        // own, so unknown parameters are dropped instead of failing the request.
        $allowed = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'scope', 'state'];
        $query = array_intersect_key($query, array_flip($allowed));
        $clientId = Util::requireString($query, 'client_id', 512);
        $redirect = Util::requireString($query, 'redirect_uri', 2048);
        $client = $this->resolveClient($clientId);
        if (!self::isRegisteredRedirectUri($redirect, $client['redirect_uris'])) {
            throw new AppError('invalid_request', 'redirect_uri is not registered for this client.', 400);
        }
        if (($query['response_type'] ?? '') !== 'code' || ($query['code_challenge_method'] ?? '') !== 'S256') {
            throw new AppError('invalid_request', 'Only authorization code with PKCE S256 is supported.', 400);
        }
        $challenge = Util::requireString($query, 'code_challenge', 128);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            throw new AppError('invalid_request', 'Invalid PKCE code challenge.', 400);
        }
        $resource = self::canonicalResource($query['resource'] ?? null);
        $scopes = $this->parseScopes(is_string($query['scope'] ?? null) ? $query['scope'] : 'lexware:read content:read accounts:read');
        return [
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => $resource,
            'scopes' => $scopes, 'state' => is_string($query['state'] ?? null) ? $query['state'] : null,
            'client_name' => $client['client_name'],
        ];
    }

    private function resolveClient(string $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT client_name,redirect_uris FROM lxmcp_oauth_clients WHERE client_id=? AND disabled_at IS NULL');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return ['client_name' => $row['client_name'], 'redirect_uris' => json_decode((string) $row['redirect_uris'], true, 16, JSON_THROW_ON_ERROR)];
        }
        if (!str_starts_with($clientId, 'https://')) {
            throw new AppError('invalid_client', 'Unknown OAuth client.', 400);
        }
        $metadata = $this->fetchClientMetadata($clientId);
        if (($metadata['client_id'] ?? null) !== $clientId || !is_array($metadata['redirect_uris'] ?? null)) {
            throw new AppError('invalid_client', 'Invalid client metadata document.', 400);
        }
        foreach ($metadata['redirect_uris'] as $uri) {
            $this->assertRedirectUri($uri);
        }
        return ['client_name' => is_string($metadata['client_name'] ?? null) ? $metadata['client_name'] : $clientId, 'redirect_uris' => $metadata['redirect_uris']];
    }

    private function fetchClientMetadata(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass']) || (($parts['port'] ?? 443) !== 443)) {
            throw new AppError('invalid_client', 'Client metadata host is not allowed.', 400);
        }
        $ips = $this->publicIps($host);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 262144, CURLOPT_RESOLVE => [$host . ':443:' . $this->curlIp($ips[0])]]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200 || !str_contains(strtolower($type), 'json') || strlen($body) > 262144) {
            throw new AppError('invalid_client', 'Client metadata could not be loaded.', 400);
        }
        return Util::jsonDecode($body);
    }

    private function publicIps(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new AppError('invalid_client', 'Client metadata host cannot be resolved.', 400);
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new AppError('invalid_client', 'Client metadata host resolves to a forbidden address.', 400);
            }
            $ips[] = $ip;
        }
        usort($ips, static fn(string $a, string $b): int => (str_contains($a, ':') ? 1 : 0) <=> (str_contains($b, ':') ? 1 : 0));
        return $ips;
    }

    private function curlIp(string $ip): string
    {
        return str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    }

    private function exchangeCode(array $post): array
    {
        $code = Util::requireString($post, 'code', 1024);
        $clientId = Util::requireString($post, 'client_id', 512);
        $redirect = Util::requireString($post, 'redirect_uri', 2048);
        $resource = self::canonicalResource($post['resource'] ?? null);
        $verifier = Util::requireString($post, 'code_verifier', 128);
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            throw new AppError('invalid_grant', 'Invalid PKCE verifier.', 400);
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM lxmcp_oauth_codes WHERE code_hash=? FOR UPDATE');
            $stmt->execute([Util::tokenHash($code)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()
                || !hash_equals((string) $row['client_id'], $clientId)
                || !hash_equals((string) $row['redirect_uri'], $redirect)
                || !hash_equals((string) $row['resource'], $resource)) {
                throw new AppError('invalid_grant', 'Authorization code is invalid or expired.', 400);
            }
            $calculated = Util::base64UrlEncode(hash('sha256', $verifier, true));
            if (!hash_equals((string) $row['code_challenge'], $calculated)) {
                throw new AppError('invalid_grant', 'PKCE verification failed.', 400);
            }
            $this->pdo->prepare('UPDATE lxmcp_oauth_codes SET used_at=NOW(6) WHERE code_hash=?')->execute([Util::tokenHash($code)]);
            $tokens = $this->issueTokens((int) $row['user_id'], $clientId, $resource, json_decode((string) $row['scopes_json'], true), Util::uuid());
            $this->pdo->commit();
            return $tokens;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function refresh(array $post): array
    {
        $token = Util::requireString($post, 'refresh_token', 1024);
        $clientId = Util::requireString($post, 'client_id', 512);
        $resource = self::canonicalResource($post['resource'] ?? null);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM lxmcp_oauth_tokens WHERE token_hash=? AND token_type='refresh' FOR UPDATE");
            $stmt->execute([Util::tokenHash($token)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new AppError('invalid_grant', 'Refresh token is invalid or expired.', 400);
            }
            if ($row['used_at'] !== null) {
                $this->pdo->prepare('UPDATE lxmcp_oauth_tokens SET revoked_at=NOW(6) WHERE family_id=?')->execute([$row['family_id']]);
                $this->pdo->commit();
                throw new AppError('invalid_grant', 'Refresh token reuse detected; the token family was revoked.', 400);
            }
            if ($row['revoked_at'] !== null || strtotime((string) $row['expires_at']) <= time()
                || !hash_equals((string) $row['client_id'], $clientId) || !hash_equals((string) $row['resource'], $resource)) {
                throw new AppError('invalid_grant', 'Refresh token is invalid or expired.', 400);
            }
            $this->pdo->prepare('UPDATE lxmcp_oauth_tokens SET used_at=NOW(6),revoked_at=NOW(6) WHERE token_hash=?')->execute([Util::tokenHash($token)]);
            $scopes = json_decode((string) $row['scopes_json'], true);
            if (!is_array($scopes)) {
                throw new AppError('invalid_grant', 'Stored OAuth scopes are invalid.', 400);
            }
            if (isset($post['scope'])) {
                if (!is_string($post['scope'])) {
                    throw new AppError('invalid_scope', 'scope must be a string.', 400);
                }
                $requested = $this->parseScopes($post['scope']);
                if (array_diff($requested, $scopes) !== []) {
                    throw new AppError('invalid_scope', 'Refresh cannot expand the original scope.', 400);
                }
                $scopes = $requested;
            }
            $tokens = $this->issueTokens((int) $row['user_id'], $clientId, $resource, $scopes, (string) $row['family_id']);
            $this->pdo->commit();
            return $tokens;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function issueTokens(int $userId, string $clientId, string $resource, array $scopes, string $family): array
    {
        $access = Util::randomToken(32);
        $refresh = Util::randomToken(32);
        $sql = 'INSERT INTO lxmcp_oauth_tokens(token_hash,token_type,family_id,client_id,user_id,resource,scopes_json,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(6), INTERVAL %d SECOND))';
        $accessStmt = $this->pdo->prepare(sprintf($sql, 600));
        $accessStmt->execute([Util::tokenHash($access), 'access', $family, $clientId, $userId, $resource, Util::jsonEncode($scopes)]);
        $refreshStmt = $this->pdo->prepare(sprintf($sql, 2592000));
        $refreshStmt->execute([Util::tokenHash($refresh), 'refresh', $family, $clientId, $userId, $resource, Util::jsonEncode($scopes)]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 600, 'refresh_token' => $refresh, 'scope' => implode(' ', $scopes), 'resource' => $resource];
    }

    private function authenticateLegacy(array $post): int
    {
        $token = is_string($post['legacy_token'] ?? null) ? trim($post['legacy_token']) : '';
        if ($token === '') {
            $mail = Util::requireString($post, 'mail', 320);
            $password = $post['password'] ?? null;
            if (!is_string($password) || $password === '' || strlen($password) > 4096) {
                throw new AppError('validation_error', "Field 'password' must be a non-empty string.", 400, false, ['field' => 'password']);
            }
            $passwordHash = self::legacyPasswordHash($password);
            sodium_memzero($password);

            $stmt = $this->pdo->prepare('SELECT ID FROM UserAccount WHERE email=? AND passwordHash=? LIMIT 1');
            $stmt->execute([$mail, $passwordHash]);
            $user = $stmt->fetchColumn();
            sodium_memzero($passwordHash);
            if ($user === false) {
                throw new AppError('login_failed', 'The existing account credentials are invalid.', 401);
            }
            return (int) $user;
        }
        if (!str_starts_with($token, 'MST:')) {
            throw new AppError('login_failed', 'The existing account credentials are invalid.', 401);
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM access_tokens WHERE access_token=? AND expires>NOW()');
        $stmt->execute([$token]);
        $user = $stmt->fetchColumn();
        sodium_memzero($token);
        if ($user === false) {
            throw new AppError('login_failed', 'The existing account token is invalid or expired.', 401);
        }
        return (int) $user;
    }

    private static function legacyPasswordHash(string $password): string
    {
        $hash = 'mopo' . $password;
        for ($round = 0; $round < 9293; $round++) {
            $hash = hash('sha256', $hash);
        }
        return $hash;
    }

    private function createWebSession(int $userId): void
    {
        $token = Util::randomToken(32);
        $csrf = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_web_sessions(session_hash,user_id,csrf_token,expires_at) VALUES (?,?,?,DATE_ADD(NOW(6), INTERVAL 12 HOUR))');
        $stmt->execute([Util::tokenHash($token), $userId, $csrf]);
        setcookie(self::SESSION_COOKIE, $token, ['expires' => time() + 43200, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        // The consent page is rendered inside this same request, so the session
        // has to be readable before the browser ever sends the cookie back.
        $_COOKIE[self::SESSION_COOKIE] = $token;
        setcookie(self::LOGIN_NONCE_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    }

    private function assertLoginNonce(array $post): void
    {
        $cookie = $_COOKIE[self::LOGIN_NONCE_COOKIE] ?? null;
        $posted = $post['login_nonce'] ?? null;
        if (!is_string($cookie) || !is_string($posted) || $cookie === '' || !hash_equals($cookie, $posted)) {
            throw new AppError('csrf_failed', 'The login form security token is invalid.', 403);
        }
    }

    /**
     * RFC 8707 resource indicators are optional and clients spell them slightly
     * differently. This deployment protects exactly one resource, so a missing
     * indicator stays unambiguous while a foreign one is still rejected.
     */
    private static function canonicalResource(mixed $value): string
    {
        $expected = Config::resourceUrl();
        if ($value === null || $value === '') {
            return $expected;
        }
        $parts = is_string($value) && strlen($value) <= 2048 ? parse_url(trim($value)) : false;
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new AppError('invalid_target', 'The OAuth resource indicator is invalid.', 400);
        }
        $normalized = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
        if (!hash_equals($expected, $normalized) && !hash_equals(Config::publicUrl(), $normalized)) {
            throw new AppError('invalid_target', 'The OAuth resource is not this MCP server.', 400);
        }
        return $expected;
    }

    private function parseScopes(string $raw): array
    {
        $requested = array_values(array_unique(array_filter(preg_split('/\s+/', trim($raw)) ?: [])));
        $unknown = array_diff($requested, Config::scopes());
        if ($requested === [] || $unknown !== []) {
            throw new AppError('invalid_scope', 'One or more requested OAuth scopes are invalid.', 400, false, ['unknown_scopes' => array_values($unknown)]);
        }
        return $requested;
    }

    private function assertRedirectUri(mixed $uri): void
    {
        if (!is_string($uri) || strlen($uri) > 2048 || str_contains($uri, '#')) {
            throw new AppError('invalid_client_metadata', 'Invalid redirect URI.', 400);
        }
        $parts = parse_url($uri);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new AppError('invalid_client_metadata', 'Redirect URIs must use HTTPS, except loopback clients.', 400);
        }
    }

    /**
     * Native apps bind an ephemeral loopback port for every authorization run.
     * RFC 8252 therefore requires an exact redirect match except for the port of
     * an HTTP loopback URI. All other URI components still have to match.
     */
    private static function isRegisteredRedirectUri(string $requested, array $registered): bool
    {
        foreach ($registered as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if (hash_equals($candidate, $requested)) {
                return true;
            }

            $expected = parse_url($candidate);
            $actual = parse_url($requested);
            if (!is_array($expected) || !is_array($actual)) {
                continue;
            }

            $expectedScheme = strtolower((string) ($expected['scheme'] ?? ''));
            $actualScheme = strtolower((string) ($actual['scheme'] ?? ''));
            $expectedHost = strtolower(trim((string) ($expected['host'] ?? ''), '[]'));
            $actualHost = strtolower(trim((string) ($actual['host'] ?? ''), '[]'));
            if ($expectedScheme !== 'http' || $actualScheme !== 'http'
                || $expectedHost !== $actualHost
                || !in_array($expectedHost, ['127.0.0.1', '::1'], true)) {
                continue;
            }

            unset($expected['port'], $actual['port']);
            $expected['scheme'] = $expectedScheme;
            $actual['scheme'] = $actualScheme;
            $expected['host'] = $expectedHost;
            $actual['host'] = $actualHost;
            if ($expected === $actual) {
                return true;
            }
        }
        return false;
    }

    private function loadAuthorizationRequest(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lxmcp_oauth_requests WHERE request_id=? AND expires_at>NOW(6)');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new AppError('invalid_request', 'Authorization request is invalid or expired.', 400);
        }
        return $row;
    }

    private function deleteAuthorizationRequest(string $id): void
    {
        $this->pdo->prepare('DELETE FROM lxmcp_oauth_requests WHERE request_id=?')->execute([$id]);
    }

    private function authorizationRedirect(array $params, array $result): void
    {
        $result['iss'] = Config::publicUrl();
        if (is_string($params['state'] ?? null)) {
            $result['state'] = $params['state'];
        }
        $separator = str_contains($params['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $params['redirect_uri'] . $separator . http_build_query($result), true, 302);
    }

    private function renderConsent(string $requestId, array $params): void
    {
        $scopes = '<ul><li>' . implode('</li><li>', array_map([self::class, 'h'], $params['scopes'])) . '</li></ul>';
        $body = '<h1>Zugriff erlauben</h1><p><strong>' . self::h((string) $params['client_name']) . '</strong> fordert folgende Berechtigungen an:</p>' . $scopes
            . '<form method="post"><input type="hidden" name="request_id" value="' . self::h($requestId) . '"><input type="hidden" name="csrf_token" value="' . self::h($this->csrfToken()) . '"><button name="action" value="approve">Erlauben</button><button name="action" value="deny">Ablehnen</button></form>';
        // Approving answers with a redirect to the client callback. A browser
        // checks that redirect against form-action, so the registered callback
        // origin has to be listed or the navigation is blocked without a trace.
        $this->html('Zugriff erlauben', $body, self::formActionSource((string) $params['redirect_uri']));
    }

    /** The origin of a registered redirect URI as a CSP source expression. */
    public static function formActionSource(string $redirectUri): ?string
    {
        $parts = parse_url($redirectUri);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true) || preg_match('/^[a-z0-9.\-\[\]:]{1,255}$/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
    }

    public function html(string $title, string $body, ?string $formAction = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'" . ($formAction !== null ? ' ' . $formAction : ''));
        header('X-Content-Type-Options: nosniff');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . self::h($title) . '</title><style>body{font:16px system-ui;max-width:720px;margin:3rem auto;padding:0 1rem;color:#18212b}label{display:block;margin:1rem 0}input{display:block;width:100%;box-sizing:border-box;padding:.6rem}button{padding:.7rem 1rem;margin:.5rem .5rem .5rem 0}</style><body>' . $body . '</body></html>';
    }

    public static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
