<?php
declare(strict_types=1);

namespace LexMcp;

final class ToolRouter
{
    private array $endpoints;

    public function __construct(
        private readonly AccountStore $accounts,
        private readonly AliasResolver $aliases,
        private readonly Validator $validator,
        private readonly LexwareClient $client,
        private readonly IdempotencyStore $idempotency,
        private readonly \PDO $pdo,
    ) {
        $this->endpoints = require Config::endpointFile();
    }

    public function definitions(): array
    {
        $common = ['account' => ['type' => 'string', 'description' => 'Configured Lexware account alias.']];
        return [
            $this->definition('lexware_search', 'Search contacts, articles, or vouchers with explicit pagination.', $common + ['entity' => ['type' => 'string'], 'parameters' => ['type' => 'object']], ['account','entity']),
            $this->definition('lexware_get', 'Get one Lexware resource, reference list, payment, file status, or operation status.', $common + ['entity' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object']], ['account','entity']),
            $this->definition('lexware_write', 'Create or update a contact, article, bookkeeping voucher, invoice draft, or credit-note draft.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'idempotency_key' => ['type' => 'string']], ['account','operation','parameters','idempotency_key']),
            $this->definition('lexware_file', 'Upload one supported file as a new voucher or attach it to an existing voucher.', $common + ['operation' => ['type' => 'string'], 'voucher_id' => ['type' => 'string'], 'source' => ['type' => 'object'], 'idempotency_key' => ['type' => 'string']], ['account','operation','source','idempotency_key']),
            $this->definition('lexware_finalize', 'Perform a separately authorized final or bookkeeping action.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string']], ['account','operation','parameters','confirm','idempotency_key']),
            $this->definition('lexware_delete', 'Perform only a documented and separately authorized deletion.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string']], ['account','operation','id','confirm','idempotency_key']),
        ];
    }

    public function call(string $tool, array $arguments, array $subject, string $traceId): array
    {
        return match ($tool) {
            'lexware_search' => $this->search($arguments, $subject, $traceId),
            'lexware_get' => $this->get($arguments, $subject, $traceId),
            'lexware_write' => $this->write($arguments, $subject, $traceId),
            'lexware_file' => $this->file($arguments, $subject, $traceId),
            'lexware_finalize' => $this->finalize($arguments, $subject, $traceId),
            'lexware_delete' => $this->delete($arguments, $subject, $traceId),
            default => throw new AppError('tool_not_found', 'Unknown MCP tool.', 404, false, ['tool' => $tool]),
        };
    }

    public function readFileResource(string $uri, array $subject): array
    {
        if (preg_match('#^lexware://files/([^/]+)/(file|invoice_file|credit_note_file)/([0-9a-f-]{36})$#i', $uri, $m) !== 1) {
            throw new AppError('resource_not_found', 'File resource does not exist.', 404);
        }
        $this->requireScope($subject, 'lexware:read');
        $accountAlias = rawurldecode($m[1]);
        $entity = $m[2];
        $id = $this->validator->uuid($m[3]);
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        $definition = $this->endpoints['get'][$entity];
        $apiKey = $this->accounts->apiKey($account);
        try {
            $response = $this->client->request($account, $apiKey, 'GET', str_replace('{id}', rawurlencode($id), $definition['path']), null, true);
        } finally {
            sodium_memzero($apiKey);
        }
        return [['uri' => $uri, 'mimeType' => $response->contentType ?: 'application/octet-stream', 'blob' => base64_encode((string) $response->data)]];
    }

    private function search(array $raw, array $subject, string $traceId): array
    {
        $args = $this->envelope($raw, ['account','entity','parameters']);
        $this->requireScope($subject, 'lexware:read');
        $entity = $this->aliases->resolve('entities', Util::requireString($args, 'entity', 96), array_keys($this->endpoints['search']));
        $definition = $this->endpoints['search'][$entity];
        $params = $this->validator->parameters(is_array($args['parameters'] ?? null) ? $args['parameters'] : [], $definition);
        $params += ['page' => 0, 'size' => min(50, $definition['maxSize'])];
        $query = $this->query($params);
        return $this->performRead($subject, Util::requireString($args, 'account', 96), $entity, $definition['path'] . ($query === '' ? '' : '?' . $query), $traceId, ['pagination_requested' => ['page' => $params['page'], 'size' => $params['size']]]);
    }

    private function get(array $raw, array $subject, string $traceId): array
    {
        $args = $this->envelope($raw, ['account','entity','id','parameters']);
        $this->requireScope($subject, 'lexware:read');
        $accountAlias = Util::requireString($args, 'account', 96);
        $entityInput = Util::requireString($args, 'entity', 96);
        $entity = $this->aliases->resolve('entities', $entityInput, ['operation_status', ...array_keys($this->endpoints['get'])]);
        if ($entity === 'operation_status') {
            $params = $this->aliases->normalizeParameters(is_array($args['parameters'] ?? null) ? $args['parameters'] : [], ['operation','idempotency_key']);
            return $this->success($traceId, $accountAlias, 'operation_status', $this->idempotency->status($subject['user_id'], $this->accounts->getForUser($subject['user_id'], $accountAlias)['id'], Util::requireString($params, 'operation', 128), Util::requireString($params, 'idempotency_key', 128)));
        }
        $definition = $this->endpoints['get'][$entity];
        $path = $definition['path'];
        if (($definition['id'] ?? false) === true) {
            $id = $this->validator->uuid(Util::requireString($args, 'id', 64));
            $path = str_replace('{id}', rawurlencode($id), $path);
            if (($definition['binary'] ?? false) === true) {
                $uri = 'lexware://files/' . rawurlencode($accountAlias) . '/' . $entity . '/' . $id;
                return $this->success($traceId, $accountAlias, $entity, ['resource_uri' => $uri], [], [['type' => 'resource_link', 'uri' => $uri, 'name' => $entity . '-' . $id]]);
            }
        }
        return $this->performRead($subject, $accountAlias, $entity, $path, $traceId);
    }

    private function write(array $raw, array $subject, string $traceId): array
    {
        $args = $this->envelope($raw, ['account','operation','id','parameters','idempotency_key']);
        $this->requireScope($subject, 'lexware:write');
        $operation = $this->aliases->resolve('operations', Util::requireString($args, 'operation', 128), array_keys($this->endpoints['write']));
        return $this->performMutation('write', $operation, $this->endpoints['write'][$operation], $args, $subject, $traceId);
    }

    private function finalize(array $raw, array $subject, string $traceId): array
    {
        if (!Config::finalizeEnabled()) {
            throw new AppError('finalize_disabled', 'Finalize operations are disabled by server configuration.', 403);
        }
        $args = $this->envelope($raw, ['account','operation','id','parameters','confirm','idempotency_key']);
        $this->requireScope($subject, 'lexware:finalize');
        if (($args['confirm'] ?? null) !== true) {
            throw new AppError('confirmation_required', 'confirm must be true for final operations.', 400);
        }
        $operation = $this->aliases->resolve('operations', Util::requireString($args, 'operation', 128), array_keys($this->endpoints['finalize']));
        if ($operation === 'voucher_book') {
            $params = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
            $params['voucherStatus'] = 'open';
            $args['parameters'] = $params;
        }
        return $this->performMutation('finalize', $operation, $this->endpoints['finalize'][$operation], $args, $subject, $traceId);
    }

    private function delete(array $raw, array $subject, string $traceId): array
    {
        if (!Config::deleteEnabled()) {
            throw new AppError('delete_disabled', 'Delete operations are disabled by server configuration.', 403);
        }
        $args = $this->envelope($raw, ['account','operation','id','parameters','confirm','idempotency_key']);
        $this->requireScope($subject, 'lexware:delete');
        if (($args['confirm'] ?? null) !== true) {
            throw new AppError('confirmation_required', 'confirm must be true for delete operations.', 400);
        }
        $operation = $this->aliases->resolve('operations', Util::requireString($args, 'operation', 128), array_keys($this->endpoints['delete']));
        $definition = $this->endpoints['delete'][$operation];
        if (($definition['special'] ?? false) !== true) {
            return $this->performMutation('delete', $operation, $definition, $args, $subject, $traceId);
        }
        $accountAlias = Util::requireString($args, 'account', 96);
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        $id = $this->validator->uuid(Util::requireString($args, 'id', 64));
        $params = $this->aliases->normalizeParameters(is_array($args['parameters'] ?? null) ? $args['parameters'] : [], ['fileId']);
        $fileId = $this->validator->uuid(Util::requireString($params, 'fileId', 64), 'fileId');
        $key = $this->validator->idempotencyKey($args['idempotency_key'] ?? null);
        $guard = $this->idempotency->begin($subject['user_id'], $account['id'], $operation, $key, ['id' => $id, 'fileId' => $fileId]);
        if (!$guard['new']) {
            return $this->success($traceId, $accountAlias, $operation, $guard['result'], ['idempotent_replay' => true]);
        }
        $apiKey = $this->accounts->apiKey($account);
        try {
            $current = $this->client->request($account, $apiKey, 'GET', '/v1/vouchers/' . rawurlencode($id))->data;
            if (!is_array($current)) {
                throw new AppError('lexware_invalid_response', 'Lexware returned no voucher object.', 502);
            }
            $files = array_values(array_filter($current['files'] ?? [], static function ($file) use ($fileId): bool {
                $id = is_array($file) ? ($file['id'] ?? null) : $file;
                return !is_string($id) || !hash_equals(strtolower($fileId), strtolower($id));
            }));
            if (count($files) === count($current['files'] ?? [])) {
                throw new AppError('file_not_attached', 'The specified file is not attached to this voucher.', 404);
            }
            $payload = $this->writableVoucher($current, ['files' => $files]);
            $response = $this->client->request($account, $apiKey, 'PUT', '/v1/vouchers/' . rawurlencode($id), $payload);
            if (!is_array($response->data)) {
                throw new AppError('lexware_uncertain_response', 'Lexware accepted the update but returned no usable operation result.', 502, false, ['lexware_status' => $response->status]);
            }
            $result = $response->data;
            $this->idempotency->complete($subject['user_id'], $account['id'], $operation, $key, $result);
            return $this->success($traceId, $accountAlias, $operation, $result);
        } catch (AppError $e) {
            $this->recordMutationError($e, $subject['user_id'], $account['id'], $operation, $key);
            throw $e;
        } finally {
            sodium_memzero($apiKey);
        }
    }

    private function file(array $raw, array $subject, string $traceId): array
    {
        $args = $this->envelope($raw, ['account','operation','voucher_id','source','idempotency_key']);
        $this->requireScope($subject, 'lexware:write');
        $operation = $this->aliases->resolve('operations', Util::requireString($args, 'operation', 128), ['upload_voucher','attach_to_voucher']);
        $source = $args['source'] ?? null;
        if (!is_array($source)) {
            throw new AppError('validation_error', 'source must be an object.', 400);
        }
        $source = $this->aliases->normalizeParameters($source, ['kind','filename','mime_type','content_base64','url','sha256']);
        $source['kind'] = $this->aliases->enum(Util::requireString($source, 'kind', 16), ['base64','https']);
        $accountAlias = Util::requireString($args, 'account', 96);
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        $key = $this->validator->idempotencyKey($args['idempotency_key'] ?? null);
        $voucherId = null;
        if ($operation === 'attach_to_voucher') {
            $voucherId = $this->validator->uuid(Util::requireString($args, 'voucher_id', 64), 'voucher_id');
        }
        [$temp, $filename, $hash, $mimeType] = $this->materializeSource($source);
        try {
            $guard = $this->idempotency->begin($subject['user_id'], $account['id'], $operation, $key, ['voucher_id' => $voucherId, 'filename' => $filename, 'sha256' => $hash]);
            if (!$guard['new']) {
                return $this->success($traceId, $accountAlias, $operation, $guard['result'], ['idempotent_replay' => true]);
            }
            $apiKey = $this->accounts->apiKey($account);
            $path = '/v1/files';
            if ($operation === 'attach_to_voucher') {
                $path = '/v1/vouchers/' . rawurlencode($voucherId) . '/files';
            }
            try {
                $result = $this->client->upload($account, $apiKey, $path, $temp, $filename, $mimeType, $operation === 'upload_voucher')->data ?? [];
                if (is_array($result)) {
                    $result['sha256'] = $hash;
                }
                $this->idempotency->complete($subject['user_id'], $account['id'], $operation, $key, $result);
                return $this->success($traceId, $accountAlias, $operation, $result);
            } catch (AppError $e) {
                $this->recordMutationError($e, $subject['user_id'], $account['id'], $operation, $key);
                throw $e;
            } finally {
                sodium_memzero($apiKey);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function performMutation(string $class, string $operation, array $definition, array $args, array $subject, string $traceId): array
    {
        $accountAlias = Util::requireString($args, 'account', 96);
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        $key = $this->validator->idempotencyKey($args['idempotency_key'] ?? null);
        $rawParams = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
        $params = $this->validator->parameters($rawParams, $definition);
        $id = null;
        if (($definition['id'] ?? false) === true) {
            $id = $this->validator->uuid(Util::requireString($args, 'id', 64));
        }
        $guard = $this->idempotency->begin($subject['user_id'], $account['id'], $operation, $key, ['id' => $id, 'parameters' => $params]);
        if (!$guard['new']) {
            return $this->success($traceId, $accountAlias, $operation, $guard['result'], ['idempotent_replay' => true]);
        }
        $apiKey = $this->accounts->apiKey($account);
        try {
            $path = $id === null ? $definition['path'] : str_replace('{id}', rawurlencode($id), $definition['path']);
            if (($definition['preceding'] ?? false) === true) {
                $preceding = $this->validator->uuid(Util::requireString($params, 'precedingSalesVoucherId', 64), 'precedingSalesVoucherId');
                unset($params['precedingSalesVoucherId']);
                $path .= '?' . http_build_query(['precedingSalesVoucherId' => $preceding] + (($definition['finalize'] ?? false) ? ['finalize' => 'true'] : []), '', '&', PHP_QUERY_RFC3986);
            } elseif (($definition['finalize'] ?? false) === true) {
                $path .= '?finalize=true';
            }
            if (($definition['preserveFiles'] ?? false) === true && $id !== null) {
                $existing = $this->client->request($account, $apiKey, 'GET', '/v1/vouchers/' . rawurlencode($id))->data;
                if (!is_array($existing)) {
                    throw new AppError('lexware_invalid_response', 'Lexware returned no voucher object.', 502);
                }
                if ($class === 'finalize' && ($existing['voucherStatus'] ?? null) === 'blank') {
                    throw new AppError('voucher_not_ready', 'OCR is not complete; the voucher is still blank.', 409, true, [], 'Poll file_status or voucher detail before booking.');
                }
                if ($class === 'finalize' && ($existing['voucherStatus'] ?? null) !== 'unchecked') {
                    throw new AppError('invalid_voucher_state', 'Only an unchecked bookkeeping voucher can be booked through this operation.', 409);
                }
                if ($class === 'write' && in_array($existing['voucherStatus'] ?? null, ['blank','unchecked'], true)) {
                    throw new AppError('finalize_required', 'Blank and unchecked vouchers cannot be changed through lexware_write; use the OCR workflow and lexware_finalize.', 409);
                }
                if ($class === 'write' && isset($params['voucherStatus']) && ($existing['voucherStatus'] ?? null) !== $params['voucherStatus']) {
                    throw new AppError('invalid_voucher_state', 'lexware_write cannot change a voucher status.', 409);
                }
                $params = $this->writableVoucher($existing, $params);
            }
            $body = $definition['method'] === 'DELETE' ? null : $params;
            $response = $this->client->request($account, $apiKey, $definition['method'], $path, $body);
            $result = $response->data;
            if ($definition['method'] !== 'DELETE' && !is_array($result)) {
                throw new AppError('lexware_uncertain_response', 'Lexware accepted the mutation but returned no usable operation result.', 502, false, ['lexware_status' => $response->status]);
            }
            $result ??= [];
            $this->idempotency->complete($subject['user_id'], $account['id'], $operation, $key, is_array($result) ? $result : ['result' => $result]);
            $this->audit($traceId, $subject['user_id'], $account['id'], $operation, 'complete', $guard['payload_hash']);
            return $this->success($traceId, $accountAlias, $operation, $result);
        } catch (AppError $e) {
            $uncertain = $this->mutationIsUncertain($e);
            $this->recordMutationError($e, $subject['user_id'], $account['id'], $operation, $key);
            $this->audit($traceId, $subject['user_id'], $account['id'], $operation, $uncertain ? 'uncertain' : 'failed', $guard['payload_hash']);
            throw $e;
        } finally {
            sodium_memzero($apiKey);
        }
    }

    private function performRead(array $subject, string $accountAlias, string $entity, string $path, string $traceId, array $extra = []): array
    {
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        $apiKey = $this->accounts->apiKey($account);
        try {
            $data = $this->client->request($account, $apiKey, 'GET', $path)->data;
        } finally {
            sodium_memzero($apiKey);
        }
        $pagination = [];
        if (is_array($data) && isset($data['number'], $data['size'])) {
            $pagination = ['page' => $data['number'], 'size' => $data['size'], 'total_pages' => $data['totalPages'] ?? null, 'total_elements' => $data['totalElements'] ?? null, 'has_next' => isset($data['last']) ? !$data['last'] : null];
        }
        return $this->success($traceId, $accountAlias, $entity, $data, $extra + ($pagination === [] ? [] : ['pagination' => $pagination]));
    }

    private function writableVoucher(array $existing, array $changes): array
    {
        $allowed = $this->endpoints['write']['voucher_update']['fields'];
        $payload = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $changes)) {
                $payload[$field] = $changes[$field];
            } elseif (array_key_exists($field, $existing)) {
                $payload[$field] = $existing[$field];
            }
        }
        return $payload;
    }

    private function materializeSource(array $source): array
    {
        $kind = Util::requireString($source, 'kind', 16);
        $filename = basename(Util::requireString($source, 'filename', 255));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new AppError('validation_error', 'Invalid filename.', 400);
        }
        $directory = Config::dataPath() . '/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppError('temporary_storage_error', 'Temporary storage is unavailable.', 500);
        }
        $temp = tempnam($directory, 'lxmcp-');
        if ($temp === false) {
            throw new AppError('temporary_storage_error', 'Temporary storage is unavailable.', 500);
        }
        chmod($temp, 0600);
        try {
            if ($kind === 'base64') {
                $encoded = Util::requireString($source, 'content_base64', 7500000);
                $data = base64_decode($encoded, true);
                if ($data === false) {
                    throw new AppError('invalid_file_encoding', 'content_base64 is not valid Base64.', 400);
                }
                file_put_contents($temp, $data, LOCK_EX);
                sodium_memzero($data);
            } elseif ($kind === 'https') {
                $this->downloadRemote(Util::requireString($source, 'url', 4096), $temp);
            } else {
                throw new AppError('unknown_value', 'source.kind must be base64 or https.', 400);
            }
            $size = filesize($temp);
            if (!is_int($size) || $size < 1 || $size > 5242880) {
                throw new AppError('file_size_invalid', 'File must be between 1 byte and 5 MB.', 400);
            }
            $actualMime = $this->detectMime($temp);
            $declared = strtolower(Util::requireString($source, 'mime_type', 128));
            if ($declared !== $actualMime) {
                throw new AppError('file_type_mismatch', 'Declared MIME type does not match the file content.', 400, false, ['detected_mime' => $actualMime]);
            }
            $declaredHash = Util::requireString($source, 'sha256', 64);
            if (preg_match('/^[0-9a-f]{64}$/i', $declaredHash) !== 1) {
                throw new AppError('validation_error', 'sha256 must contain exactly 64 hexadecimal characters.', 400, false, ['field' => 'sha256']);
            }
            $hash = hash_file('sha256', $temp);
            if (!hash_equals(strtolower($declaredHash), $hash)) {
                throw new AppError('file_checksum_mismatch', 'The file SHA-256 does not match.', 400);
            }
            return [$temp, $filename, $hash, $actualMime];
        } catch (\Throwable $e) {
            if (is_file($temp)) {
                unlink($temp);
            }
            throw $e;
        }
    }

    private function downloadRemote(string $url, string $temp): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, Config::remoteFileHosts(), true) || isset($parts['user']) || isset($parts['pass']) || (($parts['port'] ?? 443) !== 443)) {
            throw new AppError('remote_source_forbidden', 'Remote file host is not allowed.', 400);
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new AppError('remote_source_forbidden', 'Remote host resolves to a forbidden address.', 400);
            }
            $ips[] = $ip;
        }
        if ($records === false || $records === []) {
            throw new AppError('remote_source_unavailable', 'Remote host cannot be resolved.', 400);
        }
        usort($ips, static fn(string $a, string $b): int => (str_contains($a, ':') ? 1 : 0) <=> (str_contains($b, ':') ? 1 : 0));
        $pinnedIp = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $handle = fopen($temp, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 5242880, CURLOPT_RESOLVE => [$host . ':443:' . $pinnedIp]]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        fclose($handle);
        if ($ok !== true || $error !== 0 || $status < 200 || $status >= 300) {
            throw new AppError('remote_source_unavailable', 'Remote file could not be downloaded.', 400);
        }
    }

    private function detectMime(string $file): string
    {
        $head = file_get_contents($file, false, null, 0, 512);
        if (!is_string($head)) {
            throw new AppError('file_type_invalid', 'File content cannot be inspected.', 400);
        }
        if (str_starts_with($head, '%PDF-')) return 'application/pdf';
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($head, "\xff\xd8\xff")) return 'image/jpeg';
        $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($trimmed, '<?xml') || preg_match('/^<[A-Za-z_][^>]*>/', $trimmed) === 1) return 'application/xml';
        throw new AppError('file_type_invalid', 'Only PDF, PNG, JPEG, and XML voucher files are accepted.', 400);
    }

    private function envelope(array $raw, array $allowed): array
    {
        return $this->aliases->normalizeParameters($raw, $allowed);
    }

    private function query(array $params): string
    {
        foreach ($params as &$value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_string($value)) {
                $value = strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
            }
        }
        unset($value);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function requireScope(array $subject, string $scope): void
    {
        if (!in_array($scope, $subject['scopes'] ?? [], true)) {
            throw new AppError('insufficient_scope', 'The access token does not grant the required scope.', 403, false, ['required_scope' => $scope]);
        }
    }

    private function recordMutationError(AppError $error, int $userId, string $accountId, string $operation, string $key): void
    {
        if ($this->mutationIsUncertain($error)) {
            $this->idempotency->uncertain($userId, $accountId, $operation, $key, $error->errorCode);
        } else {
            $this->idempotency->failed($userId, $accountId, $operation, $key, $error->errorCode);
        }
    }

    private function mutationIsUncertain(AppError $error): bool
    {
        $status = $error->details['lexware_status'] ?? null;
        return in_array($error->errorCode, ['lexware_transport_error','lexware_uncertain_response'], true) || (is_int($status) && $status >= 500);
    }

    private function audit(string $traceId, int $userId, string $accountId, string $operation, string $outcome, string $payloadHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_audit_events(trace_id,user_id,account_id,operation,outcome,payload_hash) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$traceId, $userId, $accountId, $operation, $outcome, $payloadHash]);
    }

    private function success(string $traceId, string $account, string $action, mixed $data, array $extra = [], array $contentExtra = []): array
    {
        $structured = ['ok' => true, 'request_id' => $traceId, 'account' => $account, 'action' => $action, 'data' => $data] + $extra;
        return ['resultType' => 'complete', 'content' => array_merge([['type' => 'text', 'text' => "Lexware operation {$action} completed."]], $contentExtra), 'structuredContent' => $structured, 'isError' => false];
    }

    private function definition(string $name, string $description, array $properties, array $required): array
    {
        $readOnly = in_array($name, ['lexware_search', 'lexware_get'], true);
        $destructive = in_array($name, ['lexware_finalize', 'lexware_delete'], true);
        $titles = [
            'lexware_search' => 'Search Lexware',
            'lexware_get' => 'Get Lexware record',
            'lexware_write' => 'Write Lexware data',
            'lexware_file' => 'Process Lexware file',
            'lexware_finalize' => 'Finalize Lexware action',
            'lexware_delete' => 'Delete Lexware data',
        ];
        return [
            'name' => $name,
            'title' => $titles[$name] ?? $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => true],
            'outputSchema' => [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean'], 'request_id' => ['type' => 'string'], 'account' => ['type' => 'string'], 'action' => ['type' => 'string'], 'data' => ['description' => 'Operation-specific result.']],
                'required' => ['ok', 'request_id'],
                'additionalProperties' => true,
            ],
            'annotations' => ['readOnlyHint' => $readOnly, 'destructiveHint' => $destructive, 'idempotentHint' => $readOnly || in_array($name, ['lexware_file'], true), 'openWorldHint' => true],
        ];
    }
}
