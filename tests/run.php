<?php
declare(strict_types=1);

putenv('LEXMCP_ENV=test');

$src = dirname(__DIR__) . '/includes/lxwmcp/src/';
foreach (['AppError.php','Util.php','Config.php','SafeLogger.php','Migrator.php','Crypto.php','AccountStore.php','OAuth.php','AliasResolver.php','Validator.php','RateLimiter.php','LexwareClient.php','IdempotencyStore.php','ContentRegistry.php','ToolRouter.php','McpServer.php','Application.php'] as $file) {
    require_once $src . $file;
}
require_once __DIR__ . '/TestSuite.php';

use LexMcp\AccountStore;
use LexMcp\AliasResolver;
use LexMcp\AppError;
use LexMcp\Application;
use LexMcp\Config;
use LexMcp\ContentRegistry;
use LexMcp\Crypto;
use LexMcp\IdempotencyStore;
use LexMcp\LexwareClient;
use LexMcp\McpServer;
use LexMcp\Migrator;
use LexMcp\OAuth;
use LexMcp\RateLimiter;
use LexMcp\ToolRouter;
use LexMcp\Util;
use LexMcp\Validator;

$suite = new TestSuite();
$aliases = new AliasResolver(Config::aliasFile());
$validator = new Validator($aliases);

$suite->test('alias resolution is case-insensitive', function () use ($suite, $aliases): void {
    $suite->assertSame('contacts', $aliases->resolve('entities', 'KoNtAkTe', ['contacts','articles']));
    $suite->assertSame('purchaseinvoice', $aliases->enum('EINGANGSRECHNUNG', ['purchaseinvoice','salesinvoice']));
});

$suite->test('canonical parameter spelling is case-insensitive', function () use ($suite, $aliases): void {
    $result = $aliases->normalizeParameters(['VoucherNumber' => 'R-1'], ['voucherNumber']);
    $suite->assertSame(['voucherNumber' => 'R-1'], $result);
});

$suite->test('ambiguous aliases require clarification', function () use ($suite): void {
    $file = tempnam(sys_get_temp_dir(), 'aliases-');
    file_put_contents($file, json_encode(['entities' => ['one' => ['x'], 'two' => ['X']]]));
    try {
        $resolver = new AliasResolver($file);
        $suite->assertThrows(AppError::class, fn() => $resolver->resolve('entities', 'x', ['one','two']), 'alias_ambiguous');
    } finally {
        unlink($file);
    }
});

$suite->test('unknown parameters are rejected', function () use ($suite, $aliases): void {
    try {
        $aliases->normalizeParameters(['unsupportedFilter' => 'value'], ['page']);
        throw new RuntimeException('Expected unknown_parameter to be thrown.');
    } catch (AppError $e) {
        $suite->assertSame('unknown_parameter', $e->errorCode);
        $suite->assertSame('Unknown parameter "unsupportedFilter".', $e->getMessage());
        $suite->assertSame('unsupportedFilter', $e->details['parameter']);
        $suite->assertSame(['page'], $e->details['allowed']);
    }
});

$suite->test('unknown parameter details survive MCP error sanitization', function () use ($suite): void {
    /** @var McpServer $server */
    $server = (new ReflectionClass(McpServer::class))->newInstanceWithoutConstructor();
    $safeDetails = new ReflectionMethod($server, 'safeDetails');
    $details = $safeDetails->invoke($server, ['parameter' => 'unsupportedFilter', 'allowed' => ['page'], 'secret' => 'hidden']);
    $suite->assertSame(['parameter' => 'unsupportedFilter', 'allowed' => ['page']], $details);
});

$suite->test('pagination validation honors endpoint maximum', function () use ($suite, $validator): void {
    $definition = ['parameters' => ['page','size'], 'maxSize' => 250];
    $suite->assertSame(['page' => 0, 'size' => 250], $validator->parameters(['PAGE' => 0, 'size' => 250], $definition));
    $suite->assertThrows(AppError::class, fn() => $validator->parameters(['size' => 251], $definition), 'validation_error');
});

$suite->test('idempotency key validation', function () use ($suite, $validator): void {
    $suite->assertSame('folder:abcdef1234', $validator->idempotencyKey('folder:abcdef1234'));
    $suite->assertThrows(AppError::class, fn() => $validator->idempotencyKey('short'), 'validation_error');
});

$suite->test('secret encryption roundtrip and key fingerprints', function () use ($suite): void {
    $id = Util::uuid();
    $encrypted = Crypto::encryptApiKey('secret-api-key-123456789', $id);
    $suite->assertSame('secret-api-key-123456789', Crypto::decryptApiKey($encrypted['ciphertext'], $encrypted['nonce'], $id));
    $suite->assertSame(Crypto::fingerprint('same-key'), Crypto::fingerprint('same-key'));
});

$suite->test('production keys are generated once in private persistent storage', function () use ($suite): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lexmcp-keys-' . bin2hex(random_bytes(6));
    putenv('LEXMCP_ENV=production');
    putenv('LEXMCP_DATA_PATH=' . $directory);
    putenv('LEXMCP_ENCRYPTION_KEY');
    putenv('LEXMCP_FINGERPRINT_KEY');
    try {
        $encryptionKey = Config::encryptionKey();
        $suite->assertSame($encryptionKey, Config::encryptionKey());
        $suite->assertTrue($encryptionKey !== Config::fingerprintKey());
        $suite->assertTrue(is_file($directory . DIRECTORY_SEPARATOR . '.encryption.key'));
        $suite->assertTrue(is_file($directory . DIRECTORY_SEPARATOR . '.fingerprint.key'));
    } finally {
        foreach (['.encryption.key', '.fingerprint.key'] as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) unlink($path);
        }
        if (is_dir($directory)) rmdir($directory);
        putenv('LEXMCP_DATA_PATH');
        putenv('LEXMCP_ENV=test');
    }
});

$suite->test('handbooks and skills are discovered without code registration', function () use ($suite): void {
    $reflection = new ReflectionClass(ContentRegistry::class);
    /** @var ContentRegistry $registry */
    $registry = $reflection->newInstanceWithoutConstructor();
    $items = $registry->list(1);
    $uris = array_column($items, 'uri');
    $suite->assertTrue(in_array('lexware://handbooks/incoming-vouchers', $uris, true));
    $suite->assertTrue(in_array('skill://lexware/incoming-voucher-folder/SKILL.md', $uris, true));
});

$suite->test('modern and classic MCP version profiles are advertised', function () use ($suite): void {
    $suite->assertTrue(in_array('2026-07-28', Config::supportedVersions(), true));
    $suite->assertSame('modern', Config::protocolProfiles()['2026-07-28']);
    $suite->assertSame('legacy', Config::protocolProfiles()['2025-11-25']);
    $suite->assertSame('legacy', Config::protocolProfiles()['2024-11-05']);
});

$suite->test('unreviewed future MCP versions are not enabled by configuration alone', function () use ($suite): void {
    putenv('LEXMCP_PROTOCOL_VERSIONS=2099-01-01');
    try {
        $suite->assertThrows(RuntimeException::class, fn() => Config::protocolProfiles());
    } finally {
        putenv('LEXMCP_PROTOCOL_VERSIONS');
    }
});

$suite->test('legacy account passwords use the existing database hash', function () use ($suite): void {
    $method = new ReflectionMethod(OAuth::class, 'legacyPasswordHash');
    $suite->assertSame(
        '12628f8276bcb0d08a4d09e76de1e1998e3c294362f0ac32330380ead2aaa96a',
        $method->invoke(null, 'Correct Horse Battery Staple')
    );
});

$suite->test('server discovery advertises actual capabilities and cache hints', function () use ($suite): void {
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $content = (new ReflectionClass(ContentRegistry::class))->newInstanceWithoutConstructor();
    $server = new McpServer($oauth, $tools, $content);
    $method = new ReflectionMethod($server, 'dispatch');
    $result = $method->invoke($server, 'server/discover', [], null, Util::uuid());
    $suite->assertSame('complete', $result['resultType']);
    $suite->assertTrue(isset($result['capabilities']['tools'], $result['ttlMs'], $result['cacheScope']));
    $suite->assertSame('{"tools":{},"resources":{},"prompts":{}}', Util::jsonEncode($result['capabilities']));
});

$suite->test('classic initialize negotiates known versions and falls back compatibly', function () use ($suite): void {
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $content = (new ReflectionClass(ContentRegistry::class))->newInstanceWithoutConstructor();
    $server = new McpServer($oauth, $tools, $content);
    $dispatch = new ReflectionMethod($server, 'dispatch');

    $known = $dispatch->invoke($server, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']], null, Util::uuid(), 'legacy');
    $future = $dispatch->invoke($server, 'initialize', ['protocolVersion' => '2099-01-01'], null, Util::uuid(), 'legacy');
    $suite->assertSame('2025-06-18', $known['protocolVersion']);
    $suite->assertSame('2025-11-25', $future['protocolVersion']);
    $suite->assertSame('{"tools":{},"resources":{},"prompts":{}}', Util::jsonEncode($known['capabilities']));
});

$suite->test('routing headers are optional but conflicting values fail closed', function () use ($suite): void {
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $content = (new ReflectionClass(ContentRegistry::class))->newInstanceWithoutConstructor();
    $server = new McpServer($oauth, $tools, $content);
    $validate = new ReflectionMethod($server, 'validateRoutingHeaders');
    unset($_SERVER['HTTP_MCP_METHOD'], $_SERVER['HTTP_MCP_NAME']);
    $validate->invoke($server, 'tools/list', []);
    $_SERVER['HTTP_MCP_METHOD'] = 'resources/list';
    try {
        $suite->assertThrows(AppError::class, fn() => $validate->invoke($server, 'tools/list', []), 'header_mismatch');
    } finally {
        unset($_SERVER['HTTP_MCP_METHOD']);
    }
});

$suite->test('wire shape wins when protocol versions disagree', function () use ($suite): void {
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $content = (new ReflectionClass(ContentRegistry::class))->newInstanceWithoutConstructor();
    $server = new McpServer($oauth, $tools, $content);
    $resolve = new ReflectionMethod($server, 'resolveProtocolProfile');

    $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] = '2026-07-28';
    unset($_SERVER['HTTP_MCP_METHOD']);
    $suite->assertSame('legacy', $resolve->invoke($server, 'tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-11-25']]));
    $_SERVER['HTTP_MCP_METHOD'] = 'tools/list';
    $suite->assertSame('modern', $resolve->invoke($server, 'tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-11-25']]));
    unset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'], $_SERVER['HTTP_MCP_METHOD']);
});

$suite->test('tool catalog stays compact and deterministic', function () use ($suite): void {
    /** @var ToolRouter $router */
    $router = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $definitions = $router->definitions();
    $names = array_column($definitions, 'name');
    $suite->assertSame(['lexware_search','lexware_get','lexware_write','lexware_file','lexware_finalize','lexware_delete'], $names);
    $suite->assertTrue(!in_array('', array_column($definitions, 'title'), true));
    $suite->assertTrue(str_contains($definitions[0]['inputSchema']['properties']['parameters']['description'], 'voucherStatus'));
});

$suite->test('voucher filters normalize comma-separated enums', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['search']['vouchers'];
    $params = $validator->parameters(['belegtyp' => 'EINGANGSRECHNUNG,invoice', 'belegstatus' => 'OFFEN,paid'], $definition);
    $suite->assertSame('purchaseinvoice,invoice', $params['voucherType']);
    $suite->assertSame('open,paid', $params['voucherStatus']);
});

$suite->test('voucher item fields are strict and complete', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['write']['voucher_create'];
    $suite->assertThrows(AppError::class, fn() => $validator->parameters(['type' => 'purchaseinvoice', 'taxType' => 'gross', 'voucherItems' => [['amount' => 10]]], $definition), 'validation_error');
});

$suite->test('contact writes reject unknown nested fields and normalize country codes', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['write']['contact_create'];
    $valid = $validator->parameters(['version' => 0, 'roles' => ['customer' => []], 'person' => ['lastName' => 'Muster'], 'addresses' => ['billing' => [['countryCode' => 'de']]]], $definition);
    $suite->assertSame('DE', $valid['addresses']['billing'][0]['countryCode']);
    $suite->assertThrows(AppError::class, fn() => $validator->parameters(['version' => 0, 'roles' => ['customer' => []], 'person' => ['lastName' => 'Muster', 'ignored' => true]], $definition), 'unknown_parameter');
});

$suite->test('sales voucher validation accepts current documented wire fields', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['finalize']['invoice_create'];
    $parameters = [
        'voucherDate' => '2026-08-28T00:00:00.000+02:00',
        'address' => ['name' => 'Muster GmbH', 'countryCode' => 'de'],
        'lineItems' => [[
            'type' => 'CUSTOM', 'name' => 'Leistung', 'quantity' => 1, 'unitName' => 'Stück',
            'unitPrice' => ['currency' => 'EUR', 'netAmount' => 100.0, 'taxRatePercentage' => 19],
        ]],
        'totalPrice' => ['currency' => 'EUR'],
        'taxConditions' => ['taxType' => 'NET'],
        'shippingConditions' => ['shippingType' => 'SERVICE', 'shippingDate' => '2026-08-28T00:00:00.000+02:00'],
    ];
    $validated = $validator->parameters($parameters, $definition);
    $suite->assertSame('custom', $validated['lineItems'][0]['type']);
    $suite->assertSame(19, $validated['lineItems'][0]['unitPrice']['taxRatePercentage']);
    $suite->assertSame('DE', $validated['address']['countryCode']);
});

$suite->test('sales voucher validation rejects read-only and undocumented nested fields', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['write']['credit_note_draft_create'];
    $base = [
        'voucherDate' => '2026-08-28T00:00:00+02:00',
        'address' => ['name' => 'Muster GmbH', 'countryCode' => 'DE'],
        'lineItems' => [['type' => 'text', 'name' => 'Hinweis']],
        'totalPrice' => ['currency' => 'EUR'],
        'taxConditions' => ['taxType' => 'net'],
    ];
    $invalid = $base;
    $invalid['totalPrice']['totalGrossAmount'] = 1;
    $suite->assertThrows(AppError::class, fn() => $validator->parameters($invalid, $definition), 'unknown_parameter');
    $invalid = $base;
    $invalid['lineItems'][0]['lineItemAmount'] = 1;
    $suite->assertThrows(AppError::class, fn() => $validator->parameters($invalid, $definition), 'unknown_parameter');
});

$suite->test('enum parameters fail closed on non-string values', function () use ($suite, $validator): void {
    $definition = (require Config::endpointFile())['search']['articles'];
    $suite->assertThrows(AppError::class, fn() => $validator->parameters(['type' => 1], $definition), 'validation_error');
});

$suite->test('PDF source validation is content-based and leaves no persistent file', function () use ($suite, $aliases): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lxmcp-test-' . bin2hex(random_bytes(4));
    putenv('LEXMCP_DATA_PATH=' . $directory);
    /** @var ToolRouter $router */
    $router = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty($router, 'aliases');
    $property->setValue($router, $aliases);
    $method = new ReflectionMethod($router, 'materializeSource');
    try {
        [$path, $name, $hash] = $method->invoke($router, [
            'kind' => 'base64', 'filename' => 'receipt.pdf', 'mime_type' => 'application/pdf',
            'content_base64' => base64_encode("%PDF-1.4\n%%EOF\n"),
            'sha256' => hash('sha256', "%PDF-1.4\n%%EOF\n"),
        ]);
        $suite->assertSame('receipt.pdf', $name);
        $suite->assertSame(hash_file('sha256', $path), $hash);
        unlink($path);
    } finally {
        putenv('LEXMCP_DATA_PATH');
        $tmp = $directory . DIRECTORY_SEPARATOR . 'tmp';
        if (is_dir($tmp)) rmdir($tmp);
        if (is_dir($directory)) rmdir($directory);
    }
});

$suite->test('remote file sources are denied unless explicitly allowlisted', function () use ($suite): void {
    /** @var ToolRouter $router */
    $router = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($router, 'downloadRemote');
    $target = tempnam(sys_get_temp_dir(), 'lxmcp-ssrf-');
    try {
        $suite->assertThrows(AppError::class, fn() => $method->invoke($router, 'https://127.0.0.1/private.pdf', $target), 'remote_source_forbidden');
    } finally {
        if (is_file($target)) unlink($target);
    }
});

$suite->test('OAuth metadata exposes PKCE, rotation scopes, and the MCP resource', function () use ($suite): void {
    /** @var OAuth $oauth */
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $metadata = $oauth->authorizationServerMetadata();
    $resource = $oauth->protectedResourceMetadata();
    $suite->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
    $suite->assertSame(true, $metadata['authorization_response_iss_parameter_supported']);
    $suite->assertSame(true, $metadata['client_id_metadata_document_supported']);
    $suite->assertSame(Config::resourceUrl(), $resource['resource']);
    $suite->assertTrue(in_array('lexware:finalize', $metadata['scopes_supported'], true));
});

$suite->test('the combined discovery document answers both metadata lookups', function () use ($suite): void {
    /** @var OAuth $oauth */
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $combined = $oauth->discoveryMetadata();
    foreach (['issuer','authorization_endpoint','token_endpoint','registration_endpoint','code_challenge_methods_supported'] as $key) {
        $suite->assertTrue(isset($combined[$key]), "authorization server field {$key} is missing");
    }
    foreach (['resource','authorization_servers','bearer_methods_supported'] as $key) {
        $suite->assertTrue(isset($combined[$key]), "protected resource field {$key} is missing");
    }
    $suite->assertSame(Config::resourceUrl(), $combined['resource']);
    $suite->assertSame(Config::publicUrl(), $combined['issuer']);
});

$suite->test('MCP request authentication rejects a missing bearer token', function () use ($suite): void {
    /** @var OAuth $oauth */
    $oauth = (new ReflectionClass(OAuth::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $content = (new ReflectionClass(ContentRegistry::class))->newInstanceWithoutConstructor();
    $server = new McpServer($oauth, $tools, $content);
    $authenticate = new ReflectionMethod($server, 'authenticateRequest');
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    $suite->assertThrows(AppError::class, fn() => $authenticate->invoke($server), 'invalid_token');
});

$suite->test('authorization header survives an Apache internal redirect', function () use ($suite): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirected-token';
    try {
        $suite->assertSame('redirected-token', Util::bearerToken());
    } finally {
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
});

$suite->test('discovery documents stay reachable when the host denies dot paths', function () use ($suite): void {
    $requestPath = new ReflectionMethod(Application::class, 'requestPath');
    $route = new ReflectionMethod(Application::class, 'route');
    $denied = new ReflectionMethod(Application::class, 'deniedBeforeRouting');
    $discovery = new ReflectionMethod(Application::class, 'isDiscoveryPath');
    $previous = $_SERVER;
    try {
        $_SERVER['REQUEST_URI'] = '/index.php';
        $_SERVER['REDIRECT_URL'] = '/.well-known/oauth-authorization-server';
        $_SERVER['REDIRECT_STATUS'] = '403';
        $path = $route->invoke(null, $requestPath->invoke(null));
        $suite->assertSame('/.well-known/oauth-authorization-server', $path);
        $suite->assertTrue($denied->invoke(null));
        $suite->assertTrue($discovery->invoke(null, $path));
        $suite->assertTrue(!$discovery->invoke(null, '/accounts'));
    } finally {
        $_SERVER = $previous;
    }
});

$suite->test('metadata aliases and issuer-root fallback endpoints resolve', function () use ($suite): void {
    $route = new ReflectionMethod(Application::class, 'route');
    $suite->assertSame('/.well-known/oauth-authorization-server', $route->invoke(null, '/.well-known/openid-configuration'));
    $suite->assertSame('/.well-known/oauth-protected-resource', $route->invoke(null, '/.well-known/oauth-protected-resource/mcp'));
    $suite->assertSame('/oauth/authorize', $route->invoke(null, '/authorize'));
    $suite->assertSame('/oauth/token', $route->invoke(null, '/token'));
    $suite->assertSame('/oauth/register', $route->invoke(null, '/register'));
    $suite->assertSame('/.well-known/oauth-protected-resource', $route->invoke(null, '/oauth/protected-resource'));
    $suite->assertSame('/.well-known/oauth-authorization-server', $route->invoke(null, '/oauth/authorization-server'));
    $suite->assertSame('/mcp', $route->invoke(null, '/mcp'));
});

$suite->test('the consent page allows a redirect to the registered callback origin', function () use ($suite): void {
    $suite->assertSame('http://127.0.0.1:33418', OAuth::formActionSource('http://127.0.0.1:33418/callback'));
    $suite->assertSame('https://claude.ai', OAuth::formActionSource('https://claude.ai/api/mcp/auth_callback'));
    $suite->assertSame(null, OAuth::formActionSource('javascript:alert(1)'));
    // Only the origin survives, so nothing from the URI can extend the policy.
    $suite->assertSame('https://evil.example', OAuth::formActionSource("https://evil.example/x';script-src *"));
    $suite->assertSame(null, OAuth::formActionSource('https://ho st.example/cb'));
});

$suite->test('native loopback redirects may select an ephemeral port', function () use ($suite): void {
    $matches = new ReflectionMethod(OAuth::class, 'isRegisteredRedirectUri');
    $registered = ['http://127.0.0.1/callback', 'http://localhost/callback'];
    $suite->assertTrue($matches->invoke(null, 'http://127.0.0.1:65352/callback', $registered));
    $suite->assertTrue(!$matches->invoke(null, 'http://localhost:49152/callback', $registered));
    $suite->assertTrue(!$matches->invoke(null, 'http://127.0.0.2:65352/callback', $registered));
    $suite->assertTrue(!$matches->invoke(null, 'http://127.0.0.1:65352/other', $registered));
    $suite->assertTrue(!$matches->invoke(null, 'http://127.0.0.1:65352/callback?next=evil', $registered));
    $suite->assertTrue(!$matches->invoke(null, 'https://127.0.0.1:65352/callback', $registered));
});

$suite->test('browser routes answer a person with HTML, clients with JSON', function () use ($suite): void {
    $wantsHtml = new ReflectionMethod(Application::class, 'wantsHtml');
    $suite->assertTrue($wantsHtml->invoke(null, '/oauth/authorize', 'text/html,application/xhtml+xml'));
    $suite->assertTrue($wantsHtml->invoke(null, '/accounts', null));
    $suite->assertTrue(!$wantsHtml->invoke(null, '/oauth/token', 'text/html'));
    $suite->assertTrue(!$wantsHtml->invoke(null, '/oauth/authorize', 'application/json'));
});

$suite->test('OAuth resource indicators are optional and normalized', function () use ($suite): void {
    $canonical = new ReflectionMethod(OAuth::class, 'canonicalResource');
    $suite->assertSame(Config::resourceUrl(), $canonical->invoke(null, null));
    $suite->assertSame(Config::resourceUrl(), $canonical->invoke(null, Config::resourceUrl() . '/'));
    $suite->assertSame(Config::resourceUrl(), $canonical->invoke(null, Config::publicUrl()));
    $suite->assertThrows(AppError::class, fn() => $canonical->invoke(null, 'https://attacker.example/mcp'), 'invalid_target');
    $suite->assertThrows(AppError::class, fn() => $canonical->invoke(null, 'not-a-url'), 'invalid_target');
});

$suite->test('retry parsing and uncertain-write classification are conservative', function () use ($suite): void {
    /** @var LexwareClient $client */
    $client = (new ReflectionClass(LexwareClient::class))->newInstanceWithoutConstructor();
    $retryAfter = new ReflectionMethod($client, 'retryAfter');
    $suite->assertSame(7, $retryAfter->invoke($client, ['retry-after' => '7'], 1));

    /** @var ToolRouter $router */
    $router = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $uncertain = new ReflectionMethod($router, 'mutationIsUncertain');
    $suite->assertTrue($uncertain->invoke($router, new AppError('lexware_transport_error', 'safe', 502)));
    $suite->assertTrue($uncertain->invoke($router, new AppError('lexware_api_error', 'safe', 502, false, ['lexware_status' => 504])));
    $suite->assertSame(false, $uncertain->invoke($router, new AppError('lexware_api_error', 'safe', 503, true, ['lexware_status' => 429])));
});

$suite->test('critical tools are disabled before any account or API access', function () use ($suite): void {
    /** @var ToolRouter $router */
    $router = (new ReflectionClass(ToolRouter::class))->newInstanceWithoutConstructor();
    $suite->assertThrows(AppError::class, fn() => $router->call('lexware_finalize', [], ['scopes' => []], Util::uuid()), 'finalize_disabled');
    $suite->assertThrows(AppError::class, fn() => $router->call('lexware_delete', [], ['scopes' => []], Util::uuid()), 'delete_disabled');
});

$dsn = getenv('LEXMCP_TEST_DSN');
$dbUser = getenv('LEXMCP_TEST_DB_USER') ?: '';
$dbPass = getenv('LEXMCP_TEST_DB_PASSWORD') ?: '';

$database = static function () use ($dsn, $dbUser, $dbPass): PDO {
    if (!is_string($dsn) || $dsn === '') {
        throw new SkipTest('Set LEXMCP_TEST_DSN for MySQL integration tests.');
    }
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Migrator::applyPending($pdo);
    return $pdo;
};

$suite->test('account separation and case-insensitive aliases', function () use ($suite, $database): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_accounts');
    $store = new AccountStore($pdo);
    $store->save(1001, 'Firma Nord', 'key-one-1234567890', '00000000-0000-4000-8000-000000000001', 'Nord');
    $store->save(1002, 'Firma Nord', 'key-two-1234567890', '00000000-0000-4000-8000-000000000002', 'Sued');
    $suite->assertSame('Nord', $store->getForUser(1001, 'FIRMA_NORD')['organization_name']);
    $suite->assertSame('Sued', $store->getForUser(1002, 'firma-nord')['organization_name']);
});

$suite->test('idempotency prevents duplicate writes and payload changes', function () use ($suite, $database): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_idempotency');
    $store = new IdempotencyStore($pdo);
    $first = $store->begin(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'abcdefgh', ['x' => 1]);
    $suite->assertTrue($first['new']);
    $store->complete(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'abcdefgh', ['id' => 'result']);
    $second = $store->begin(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'abcdefgh', ['x' => 1]);
    $suite->assertSame(false, $second['new']);
    $suite->assertThrows(AppError::class, fn() => $store->begin(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'abcdefgh', ['x' => 2]), 'idempotency_conflict');

    $failed = $store->begin(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'ijklmnop', ['x' => 3]);
    $suite->assertTrue($failed['new']);
    $store->failed(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'ijklmnop', 'validation_error');
    $retry = $store->begin(1, '00000000-0000-4000-8000-000000000001', 'voucher_create', 'ijklmnop', ['x' => 3]);
    $suite->assertTrue($retry['new']);
});

$suite->test('global limiter spaces sequential reservations', function () use ($suite, $database): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_rate_limits');
    $limiter = new RateLimiter($pdo);
    $start = microtime(true);
    $limiter->acquire(str_repeat('a', 64));
    $limiter->acquire(str_repeat('a', 64));
    $suite->assertTrue(microtime(true) - $start >= 0.50, 'Second slot was not delayed.');
});

$suite->test('parallel limiter workers share one database queue', function () use ($suite, $database, $dsn, $dbUser, $dbPass): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_rate_limits');
    $output = tempnam(sys_get_temp_dir(), 'limiter-');
    $workers = [];
    for ($i = 0; $i < 3; $i++) {
        $command = [PHP_BINARY, __DIR__ . '/limiter_worker.php', (string) $dsn, (string) $dbUser, (string) $dbPass, $output];
        $workers[] = proc_open($command, [], $pipes);
    }
    foreach ($workers as $worker) {
        if (is_resource($worker)) proc_close($worker);
    }
    $times = array_map('floatval', file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    unlink($output);
    sort($times);
    $suite->assertSame(3, count($times));
    $suite->assertTrue(($times[1] - $times[0]) >= 0.50 && ($times[2] - $times[1]) >= 0.50, 'Parallel slots overlap.');
});

$suite->test('OAuth PKCE tokens are opaque, audience-bound, rotated, and reuse-revoked', function () use ($suite, $database): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_oauth_codes');
    $pdo->exec('DELETE FROM lxmcp_oauth_tokens');
    $pdo->exec('DELETE FROM lxmcp_oauth_clients');
    $oauth = new OAuth($pdo);
    $client = $oauth->register([
        'client_name' => 'Integration test',
        'redirect_uris' => ['http://127.0.0.1:49152/callback'],
        'application_type' => 'native',
        'grant_types' => ['refresh_token', 'authorization_code'],
        'response_types' => ['code'],
    ]);
    $verifier = str_repeat('a', 43);
    $challenge = Util::base64UrlEncode(hash('sha256', $verifier, true));
    $code = Util::randomToken(32);
    $insert = $pdo->prepare("INSERT INTO lxmcp_oauth_codes(code_hash,client_id,user_id,redirect_uri,resource,scopes_json,code_challenge,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(6), INTERVAL 5 MINUTE))");
    $insert->execute([Util::tokenHash($code), $client['client_id'], 42, $client['redirect_uris'][0], Config::resourceUrl(), Util::jsonEncode(['lexware:read','content:read']), $challenge]);
    $tokens = $oauth->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $client['redirect_uris'][0], 'client_id' => $client['client_id'], 'code_verifier' => $verifier, 'resource' => Config::resourceUrl()]);
    $suite->assertTrue(!str_contains($tokens['access_token'], 'MST:'));
    $plainStored = $pdo->prepare('SELECT COUNT(*) FROM lxmcp_oauth_tokens WHERE token_hash IN (?,?)');
    $plainStored->execute([$tokens['access_token'], $tokens['refresh_token']]);
    $suite->assertSame(0, (int) $plainStored->fetchColumn());
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
    $suite->assertSame(42, $oauth->authenticateAccessToken()['user_id']);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $rotated = $oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id'], 'resource' => Config::resourceUrl(), 'scope' => 'lexware:read']);
    $suite->assertTrue($rotated['refresh_token'] !== $tokens['refresh_token']);
    $suite->assertThrows(AppError::class, fn() => $oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client['client_id'], 'resource' => Config::resourceUrl()]), 'invalid_grant');
    $family = $pdo->query("SELECT family_id FROM lxmcp_oauth_tokens LIMIT 1")->fetchColumn();
    $revoked = $pdo->prepare('SELECT COUNT(*) FROM lxmcp_oauth_tokens WHERE family_id=? AND revoked_at IS NULL');
    $revoked->execute([$family]);
    $suite->assertSame(0, (int) $revoked->fetchColumn());
});

$suite->test('fake Lexware API exercises pagination, 429 backoff, and non-retried writes', function () use ($suite, $database): void {
    $pdo = $database();
    $pdo->exec('DELETE FROM lxmcp_rate_limits');
    $port = random_int(18080, 18999);
    $router = __DIR__ . '/fake_lexware/router.php';
    $counter = tempnam(sys_get_temp_dir(), 'lxmcp-api-count-');
    file_put_contents($counter, '0');
    putenv('LEXMCP_FAKE_COUNTER=' . $counter);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        putenv('LEXMCP_FAKE_COUNTER');
        if (is_file($counter)) unlink($counter);
        throw new RuntimeException('Could not start fake Lexware API.');
    }
    try {
        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (!$ready) throw new RuntimeException('Fake Lexware API did not start.');
        putenv("LEXMCP_LEXWARE_BASE_URL=http://127.0.0.1:{$port}");
        $client = new LexwareClient(new RateLimiter($pdo));
        $account = ['api_key_fingerprint' => str_repeat('b', 64)];
        $page = $client->request($account, 'test-key', 'GET', '/v1/contacts?page=3&size=12')->data;
        $suite->assertSame(3, $page['number']);
        $suite->assertSame(12, $page['size']);
        $suite->assertThrows(AppError::class, fn() => $client->request($account, 'test-key', 'GET', '/v1/contacts?simulate=rate-limit'), 'lexware_api_error');
        $writeAccount = ['api_key_fingerprint' => str_repeat('c', 64)];
        $before = (int) file_get_contents($counter);
        $suite->assertThrows(AppError::class, fn() => $client->request($writeAccount, 'test-key', 'POST', '/v1/vouchers?simulate=gateway-timeout', ['type' => 'purchaseinvoice']), 'lexware_api_error');
        $suite->assertSame(1, (int) file_get_contents($counter) - $before, 'A write was unexpectedly retried.');
    } finally {
        putenv('LEXMCP_LEXWARE_BASE_URL');
        putenv('LEXMCP_FAKE_COUNTER');
        proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
        if (is_file($counter)) unlink($counter);
    }
});

$suite->finish();
