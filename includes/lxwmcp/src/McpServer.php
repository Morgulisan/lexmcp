<?php
declare(strict_types=1);

namespace LexMcp;

final class McpServer
{
    private const SERVER_NAME = 'de.mopoliti.lexware-office';
    private const SERVER_VERSION = '1.0.0';

    public function __construct(
        private readonly OAuth $oauth,
        private readonly ToolRouter $tools,
        private readonly ContentRegistry $content,
    ) {}

    public function handle(): void
    {
        $traceId = Util::uuid();
        $started = microtime(true);
        $authenticated = false;
        try {
            $this->validateHttpRequest();
            $body = file_get_contents('php://input', false, null, 0, 10485761);
            if (!is_string($body) || strlen($body) > 10485760) {
                throw new AppError('request_too_large', 'MCP request exceeds 10 MiB.', 413);
            }
            $request = Util::jsonDecode($body);
            $this->validateEnvelope($request);
            $method = (string) $request['method'];
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $this->validateRoutingHeaders($method, $params);
            $this->validateVersion($method, $params);
            $subject = $method === 'server/discover' ? null : $this->oauth->authenticateAccessToken();
            $authenticated = $subject !== null;
            $result = $this->dispatch($method, $params, $subject, $traceId);
            $this->respond(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $this->stamp($result)], 200);
            SafeLogger::log('mcp_request', ['trace_id' => $traceId, 'operation' => $method, 'status' => 'complete', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        } catch (AppError $e) {
            $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
            if ($method === 'tools/call' && isset($request['id']) && $authenticated && $e->errorCode !== 'insufficient_scope') {
                $toolResult = [
                    'resultType' => 'complete',
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'structuredContent' => ['ok' => false, 'request_id' => $traceId, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'retryable' => $e->retryable, 'details' => $this->safeDetails($e->details), 'suggested_action' => $e->suggestedAction],
                    'isError' => true,
                ];
                $this->respond(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $this->stamp($toolResult)], 200);
            } else {
                $code = match ($e->errorCode) {
                    'invalid_json' => -32700,
                    'invalid_request' => -32600,
                    'method_not_found' => -32601,
                    'validation_error', 'unknown_parameter', 'resource_not_found', 'prompt_not_found' => -32602,
                    'unsupported_protocol_version' => -32022,
                    'header_mismatch' => -32020,
                    default => -32000,
                };
                $id = $request['id'] ?? null;
                $this->respond(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $e->getMessage(), 'data' => ['error_code' => $e->errorCode, 'trace_id' => $traceId, 'details' => $this->safeDetails($e->details)]], '_meta' => $this->serverMeta()], $e->httpStatus);
            }
            SafeLogger::log('mcp_error', ['trace_id' => $traceId, 'operation' => $method, 'status' => 'error', 'http_status' => $e->httpStatus, 'error_code' => $e->errorCode, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        } catch (\Throwable $e) {
            SafeLogger::log('mcp_error', ['trace_id' => $traceId, 'status' => 'error', 'http_status' => 500, 'error_code' => 'internal_error']);
            $this->respond(['jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'error' => ['code' => -32603, 'message' => 'Internal server error.', 'data' => ['trace_id' => $traceId]], '_meta' => $this->serverMeta()], 500);
        }
    }

    private function dispatch(string $method, array $params, ?array $subject, string $traceId): array
    {
        if ($method === 'server/discover') {
            Util::assertKeys($params, ['_meta']);
            return ['resultType' => 'complete', 'supportedVersions' => Config::supportedVersions(), 'capabilities' => ['tools' => [], 'resources' => [], 'prompts' => []], 'instructions' => 'Select an account from lexware://accounts. Use read, write, finalize, and delete tools according to their separate safety boundaries.', 'ttlMs' => 300000, 'cacheScope' => 'public'];
        }
        if ($subject === null) {
            throw new AppError('invalid_token', 'Authentication required.', 401);
        }
        return match ($method) {
            'ping' => ['resultType' => 'complete'],
            'tools/list' => ['resultType' => 'complete', 'tools' => $this->tools->definitions(), 'ttlMs' => 300000, 'cacheScope' => 'private'],
            'tools/call' => $this->callTool($params, $subject, $traceId),
            'resources/list' => $this->listResources($params, $subject),
            'resources/templates/list' => $this->listResourceTemplates($params, $subject),
            'resources/read' => $this->readResource($params, $subject),
            'prompts/list' => $this->listPrompts($params, $subject),
            'prompts/get' => $this->getPrompt($params, $subject),
            default => throw new AppError('method_not_found', 'MCP method not found.', 404),
        };
    }

    private function callTool(array $params, array $subject, string $traceId): array
    {
        Util::assertKeys($params, ['name','arguments','_meta']);
        $name = Util::requireString($params, 'name', 128);
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new AppError('validation_error', 'Tool arguments must be an object.', 400);
        }
        return $this->tools->call($name, $arguments, $subject, $traceId);
    }

    private function listResources(array $params, array $subject): array
    {
        $this->scope($subject, 'content:read');
        Util::assertKeys($params, ['cursor','_meta']);
        return ['resultType' => 'complete', 'resources' => $this->content->list($subject['user_id']), 'ttlMs' => 60000, 'cacheScope' => 'private'];
    }

    private function listResourceTemplates(array $params, array $subject): array
    {
        $this->scope($subject, 'content:read');
        Util::assertKeys($params, ['cursor','_meta']);
        return ['resultType' => 'complete', 'resourceTemplates' => $this->content->templates(), 'ttlMs' => 300000, 'cacheScope' => 'public'];
    }

    private function readResource(array $params, array $subject): array
    {
        Util::assertKeys($params, ['uri','_meta']);
        $uri = Util::requireString($params, 'uri', 4096);
        if (str_starts_with($uri, 'lexware://files/')) {
            return ['resultType' => 'complete', 'contents' => $this->tools->readFileResource($uri, $subject), 'ttlMs' => 0, 'cacheScope' => 'private'];
        }
        $this->scope($subject, $uri === 'lexware://accounts' ? 'accounts:read' : 'content:read');
        return ['resultType' => 'complete', 'contents' => $this->content->read($subject['user_id'], $uri), 'ttlMs' => $uri === 'lexware://accounts' ? 0 : 300000, 'cacheScope' => 'private'];
    }

    private function listPrompts(array $params, array $subject): array
    {
        $this->scope($subject, 'content:read');
        Util::assertKeys($params, ['cursor','_meta']);
        return ['resultType' => 'complete', 'prompts' => $this->content->prompts(), 'ttlMs' => 300000, 'cacheScope' => 'public'];
    }

    private function getPrompt(array $params, array $subject): array
    {
        $this->scope($subject, 'content:read');
        Util::assertKeys($params, ['name','arguments','_meta']);
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new AppError('validation_error', 'Prompt arguments must be an object.', 400);
        }
        return ['resultType' => 'complete'] + $this->content->prompt(Util::requireString($params, 'name', 128), $arguments, $subject['user_id']);
    }

    private function validateHttpRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new AppError('method_not_allowed', 'The MCP endpoint only accepts POST.', 405);
        }
        $contentType = strtolower((string) Util::header('Content-Type'));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new AppError('unsupported_media_type', 'Content-Type must be application/json.', 415);
        }
        $origin = Util::header('Origin');
        if ($origin !== null && !in_array($origin, Config::allowedOrigins(), true)) {
            throw new AppError('origin_forbidden', 'Origin is not allowed.', 403);
        }
        $expectedHost = strtolower((string) parse_url(Config::publicUrl(), PHP_URL_HOST));
        $host = strtolower(explode(':', (string) Util::header('Host'))[0]);
        if ($host !== '' && $expectedHost !== '' && !hash_equals($expectedHost, $host)) {
            throw new AppError('host_forbidden', 'Host header is not allowed.', 403);
        }
    }

    private function validateEnvelope(array $request): void
    {
        Util::assertKeys($request, ['jsonrpc','id','method','params']);
        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null) || !array_key_exists('id', $request) || !(is_int($request['id']) || is_string($request['id']))) {
            throw new AppError('invalid_request', 'Invalid JSON-RPC 2.0 request envelope.', 400);
        }
        if (isset($request['params']) && !is_array($request['params'])) {
            throw new AppError('invalid_request', 'JSON-RPC params must be an object.', 400);
        }
    }

    private function validateRoutingHeaders(string $method, array $params): void
    {
        $headerMethod = Util::header('Mcp-Method');
        if ($headerMethod === null || !hash_equals($method, $headerMethod)) {
            throw new AppError('header_mismatch', 'Mcp-Method does not match the JSON-RPC method.', 400);
        }
        if (in_array($method, ['tools/call','resources/read','prompts/get'], true)) {
            $expected = $method === 'resources/read' ? ($params['uri'] ?? null) : ($params['name'] ?? null);
            $headerName = Util::header('Mcp-Name');
            if (!is_string($expected) || $headerName === null || !hash_equals($expected, $headerName)) {
                throw new AppError('header_mismatch', 'Mcp-Name does not match the request target.', 400);
            }
        }
    }

    private function validateVersion(string $method, array $params): void
    {
        if ($method === 'server/discover') {
            return;
        }
        $header = Util::header('MCP-Protocol-Version');
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $bodyVersion = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
        if (!is_string($header) || !in_array($header, Config::supportedVersions(), true)) {
            throw new AppError('unsupported_protocol_version', 'Unsupported MCP protocol version.', 400, false, ['supportedVersions' => Config::supportedVersions()]);
        }
        if ($bodyVersion !== null && (!is_string($bodyVersion) || !hash_equals($header, $bodyVersion))) {
            throw new AppError('header_mismatch', 'MCP protocol versions in header and body differ.', 400);
        }
    }

    private function scope(array $subject, string $required): void
    {
        if (!in_array($required, $subject['scopes'], true)) {
            throw new AppError('insufficient_scope', 'The access token does not grant the required scope.', 403, false, ['required_scope' => $required]);
        }
    }

    private function stamp(array $result): array
    {
        $result['_meta'] = array_merge(is_array($result['_meta'] ?? null) ? $result['_meta'] : [], $this->serverMeta());
        return $result;
    }

    private function serverMeta(): array
    {
        return ['io.modelcontextprotocol/serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION]];
    }

    private function safeDetails(array $details): array
    {
        $allowed = ['candidates','allowed','field','required_scope','supportedVersions','lexware_status','operation','detected_mime'];
        return array_intersect_key($details, array_flip($allowed));
    }

    private function respond(array $body, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($status === 401) {
            header('WWW-Authenticate: Bearer resource_metadata="' . Config::publicUrl() . '/.well-known/oauth-protected-resource"');
        } elseif ($status === 403) {
            header('WWW-Authenticate: Bearer error="insufficient_scope", resource_metadata="' . Config::publicUrl() . '/.well-known/oauth-protected-resource"');
        }
        echo Util::jsonEncode($body);
    }
}
