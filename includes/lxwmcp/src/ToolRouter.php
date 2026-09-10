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

    public function definitions(?array $scopes = null, ?int $userId = null): array
    {
        $common = ['account' => ['type' => 'string', 'description' => 'Configured Lexware account alias.']];
        $searchParameters = [
            'type' => 'object',
            'description' => 'Allowed filters by entity. vouchers: voucherType and voucherStatus (required), archived, contactId, voucherDateFrom, voucherDateTo, createdDateFrom, createdDateTo, updatedDateFrom, updatedDateTo, voucherNumber, page, size, sort. contacts: email, name, number, customer, vendor, page, size, sort. articles: articleNumber, gtin, type, page, size, sort.',
        ];
        $definitions = [
            $this->definition('lexware_search', 'Search contacts, articles, or vouchers with entity-specific filters and explicit pagination.', $common + ['entity' => ['type' => 'string', 'enum' => ['contacts','articles','vouchers']], 'parameters' => $searchParameters], ['account','entity']),
            $this->definition('lexware_get', 'Get one Lexware resource, reference list, payment, file status, or operation status.', $common + ['entity' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object']], ['account','entity']),
            $this->definition('lexware_write', 'Create or update a contact, article, bookkeeping voucher, invoice draft, or credit-note draft. For a new voucher with a PDF use lexware_file upload_voucher first. To add a document to an existing voucher, use lexware_file attach_to_voucher instead of voucher_update.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'idempotency_key' => ['type' => 'string']], ['account','operation','parameters','idempotency_key']),
            $this->definition('lexware_file', 'Use prepare_upload with source filename, mime_type, size_bytes and sha256; PUT local bytes to the returned URL, then upload_voucher or attach_to_voucher with source kind upload and upload_id. Limit: 4500000 bytes. Only voucher operations require idempotency_key.', $common + ['operation' => ['type' => 'string', 'enum' => ['prepare_upload','upload_voucher','attach_to_voucher']], 'voucher_id' => ['type' => 'string'], 'source' => $this->fileSourceSchema(), 'idempotency_key' => ['type' => 'string']], ['account','operation','source']),
            $this->definition('lexware_finalize', 'Perform a separately authorized final or bookkeeping action.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string']], ['account','operation','parameters','confirm','idempotency_key']),
            $this->definition('lexware_delete', 'Perform only a documented and separately authorized deletion.', $common + ['operation' => ['type' => 'string'], 'id' => ['type' => 'string'], 'parameters' => ['type' => 'object'], 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string']], ['account','operation','id','confirm','idempotency_key']),
        ];
        $definitions[] = $this->definition('lexware_describe', 'Describe supported operations, required fields, enum values, limits and current permission blockers. Optional tool and operation filters.', ['tool' => ['type' => 'string'], 'operation' => ['type' => 'string']], []);
        foreach ($definitions as &$definition) {
            $class = substr($definition['name'], strlen('lexware_'));
            if (!isset($this->endpoints[$class])) continue;
            $selector = in_array($class, ['get','search'], true) ? 'entity' : 'operation';
            $operations = array_keys($this->endpoints[$class]);
            if ($class === 'get') $operations[] = 'operation_status';
            $definition['inputSchema']['properties'][$selector]['enum'] = $operations;
            $definition['inputSchema']['properties']['parameters']['description'] = ($definition['inputSchema']['properties']['parameters']['description'] ?? '') . ' Operation-specific fields; use lexware_describe for required fields and enum values.';
            $branches = [];
            foreach ($operations as $operation) {
                $parameters = $this->parameterSchema($operation, $this->endpoints[$class][$operation] ?? []);
                $branches[] = ['properties' => [$selector => ['enum' => [$operation]], 'parameters' => $parameters], 'required' => $parameters['required'] === [] ? [$selector] : [$selector, 'parameters']];
            }
            $definition['inputSchema']['anyOf'] = $branches;
        }
        unset($definition);
        if ($scopes === null) {
            return $definitions;
        }
        return array_values(array_filter($definitions, fn(array $definition): bool => $this->isAvailable((string) $definition['name'], $scopes, $userId)));
    }

    public function isAvailable(string $tool, array $scopes, ?int $userId = null): bool
    {
        $required = match ($tool) {
            'lexware_search', 'lexware_get', 'lexware_describe' => 'lexware:read',
            'lexware_write', 'lexware_file' => 'lexware:write',
            'lexware_finalize' => 'lexware:finalize',
            'lexware_delete' => 'lexware:delete',
            default => null,
        };
        if ($required === null || !in_array($required, $scopes, true)) {
            return false;
        }
        return ($tool !== 'lexware_finalize' || ($userId !== null && (new UserSettings($this->pdo))->finalizeEnabled($userId)))
            && ($tool !== 'lexware_delete' || Config::deleteEnabled());
    }

    public function call(string $tool, array $arguments, array $subject, string $traceId): array
    {
        try { return match ($tool) {
            'lexware_describe' => $this->describe($arguments, $subject, $traceId),
            'lexware_search' => $this->search($arguments, $subject, $traceId),
            'lexware_get' => $this->get($arguments, $subject, $traceId),
            'lexware_write' => $this->write($arguments, $subject, $traceId),
            'lexware_file' => $this->file($arguments, $subject, $traceId),
            'lexware_finalize' => $this->finalize($arguments, $subject, $traceId),
            'lexware_delete' => $this->delete($arguments, $subject, $traceId),
            default => throw new AppError('tool_not_found', 'Unknown MCP tool.', 404, false, ['tool' => $tool]),
        }; } catch (AppError $e) {
            $operation = $arguments['operation'] ?? $arguments['entity'] ?? $tool;
            if (!is_string($operation)) $operation = $tool;
            $operation = substr($operation, 0, 128);
            throw new AppError($e->errorCode, $operation . ': ' . $e->getMessage(), $e->httpStatus, $e->retryable, $e->details + ['operation' => $operation], $e->suggestedAction);
        }
    }

    private function parameterSchema(string $operation, array $definition): array
    {
        $fields = $definition['parameters'] ?? $definition['fields'] ?? [];
        $required = $definition['required'] ?? [];
        if ($operation === 'voucher_book') $required = array_values(array_diff($required, ['voucherStatus']));
        if ($operation === 'operation_status') $fields = $required = ['operation','idempotency_key'];
        if ($operation === 'voucher_file_remove') $fields = $required = ['fileId'];
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = ['description' => $field];
            if (isset($definition['enums'][$field])) $properties[$field] = ['type' => 'string', 'enum' => $definition['enums'][$field]];
            if (isset($definition['enumLists'][$field])) $properties[$field] = ['type' => 'string', 'description' => 'Comma-separated values: ' . implode(', ', $definition['enumLists'][$field])];
        }
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => true];
    }

    private function fileSourceSchema(): array
    {
        $metadata = ['filename' => ['type' => 'string'], 'mime_type' => ['type' => 'string', 'enum' => UploadStore::MIME_TYPES], 'sha256' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$']];
        return ['anyOf' => [
            ['type' => 'object', 'properties' => $metadata + ['size_bytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => UploadStore::MAX_BYTES]], 'required' => ['filename','mime_type','sha256','size_bytes'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['upload']], 'upload_id' => ['type' => 'string']], 'required' => ['kind','upload_id'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => $metadata + ['kind' => ['type' => 'string', 'enum' => ['base64']], 'content_base64' => ['type' => 'string']], 'required' => ['kind','filename','mime_type','sha256','content_base64'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => $metadata + ['kind' => ['type' => 'string', 'enum' => ['https']], 'url' => ['type' => 'string']], 'required' => ['kind','filename','mime_type','sha256','url'], 'additionalProperties' => false],
        ]];
    }

    private function describe(array $raw, array $subject, string $traceId): array
    {
        $this->requireScope($subject, 'lexware:read');
        $args = $this->envelope($raw, ['tool','operation']);
        $catalog = [];
        foreach ($this->definitions() as $tool) {
            $name = $tool['name'];
            $class = substr($name, strlen('lexware_'));
            $scope = match ($class) { 'write','file' => 'lexware:write', 'finalize' => 'lexware:finalize', 'delete' => 'lexware:delete', default => 'lexware:read' };
            $blockers = [];
            if (!in_array($scope, $subject['scopes'], true)) $blockers[] = 'missing_connection_scope:' . $scope;
            if ($class === 'finalize' && !(new UserSettings($this->pdo))->finalizeEnabled((int) $subject['user_id'])) $blockers[] = 'user_finalize_disabled';
            if ($class === 'delete' && !Config::deleteEnabled()) $blockers[] = 'server_delete_disabled';
            $operations = [];
            foreach ($this->endpoints[$class] ?? [] as $operation => $definition) {
                $required = $definition['required'] ?? [];
                if ($operation === 'voucher_book') $required = array_values(array_diff($required, ['voucherStatus']));
                $operations[$operation] = ['fields' => $definition['parameters'] ?? $definition['fields'] ?? [], 'required_parameters' => $required, 'enums' => $definition['enums'] ?? [], 'nested_enums' => Validator::nestedEnums($definition), 'comma_separated_enums' => $definition['enumLists'] ?? [], 'requires_id' => $definition['id'] ?? false, 'requires_confirmation' => in_array($class, ['finalize','delete'], true), 'requires_idempotency_key' => in_array($class, ['write','finalize','delete'], true)];
            }
            if ($class === 'get') $operations['operation_status'] = ['required_parameters' => ['operation','idempotency_key']];
            if ($class === 'delete' && isset($operations['voucher_file_remove'])) {
                $operations['voucher_file_remove']['fields'] = ['fileId'];
                $operations['voucher_file_remove']['required_parameters'] = ['fileId'];
            }
            if ($class === 'file') $operations = [
                'prepare_upload' => ['required_source_fields' => ['filename','mime_type','size_bytes','sha256'], 'requires_idempotency_key' => false],
                'upload_voucher' => ['source_schema' => $this->fileSourceSchema(), 'requires_idempotency_key' => true],
                'attach_to_voucher' => ['description' => 'Attach a missing document to an existing bookkeeping voucher through the separate file endpoint; the voucher_update restriction on unchecked vouchers does not apply to this MCP operation. Read the voucher afterwards to verify the file association.', 'source_schema' => $this->fileSourceSchema(), 'requires_idempotency_key' => true, 'requires_voucher_id' => true, 'requires_confirmation' => false],
            ];
            $catalog[$name] = ['required_scope' => $scope, 'available' => $blockers === [], 'blockers' => $blockers, 'input_schema' => $tool['inputSchema'], 'operations' => $operations];
        }
        if (isset($args['tool'])) {
            $name = Util::requireString($args, 'tool', 96);
            if (!isset($catalog[$name])) throw new AppError('unknown_value', 'tool must be one of: ' . implode(', ', array_keys($catalog)), 400, false, ['field' => 'tool', 'allowed' => array_keys($catalog)]);
            $catalog = [$name => $catalog[$name]];
        }
        if (isset($args['operation'])) {
            $operation = Util::requireString($args, 'operation', 128);
            $allowed = [];
            foreach ($catalog as $name => &$entry) {
                $allowed = array_merge($allowed, array_keys($entry['operations']));
                if (!isset($entry['operations'][$operation])) { unset($catalog[$name]); continue; }
                $entry['operations'] = [$operation => $entry['operations'][$operation]];
            }
            unset($entry);
            if ($catalog === []) throw new AppError('unknown_value', 'operation must be one of: ' . implode(', ', $allowed), 400, false, ['field' => 'operation', 'allowed' => $allowed]);
        }
        return $this->success($traceId, '', 'describe', ['tools' => $catalog, 'max_file_size_bytes' => UploadStore::MAX_BYTES, 'mime_types' => UploadStore::MIME_TYPES, 'upload_authorization_seconds' => 900, 'file_ttl_seconds' => 3660]);
    }

    private function stagedFile(array $subject, array $account, string $alias, string $operation, string $key, ?string $voucherId, string $uploadId, string $traceId): array
    {
        $uploads = new UploadStore($this->pdo);
        $meta = $uploads->claim($uploadId, $subject, $account, $operation, $key, $voucherId);
        $guard = $this->idempotency->begin($subject['user_id'], $account['id'], $operation, $key, ['voucher_id' => $voucherId, 'filename' => $meta['filename'], 'sha256' => $meta['sha256']]);
        if (!$guard['new']) {
            $this->cleanupUpload($uploads, $uploadId, $traceId);
            return $this->success($traceId, $alias, $operation, $guard['result'], ['idempotent_replay' => true]);
        }
        $temp = false;
        $apiKey = null;
        try {
            $directory = Config::dataPath() . '/tmp';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new AppError('temporary_storage_error', 'Cannot create working directory.', 500);
            $temp = tempnam($directory, 'lxmcp-');
            if ($temp === false) throw new AppError('temporary_storage_error', 'Cannot create working file.', 500);
            chmod($temp, 0600);
            $uploads->copyTo($uploadId, $temp);
            $apiKey = $this->accounts->apiKey($account);
            $path = $operation === 'upload_voucher' ? '/v1/files' : '/v1/vouchers/' . rawurlencode($voucherId) . '/files';
            $response = $this->client->upload($account, $apiKey, $path, $temp, $meta['filename'], $meta['mime_type'], $operation === 'upload_voucher');
            if (!is_array($response->data) || $response->data === []) throw new AppError('lexware_uncertain_response', 'Lexware returned no usable upload result.', 502);
            $result = $response->data + ['sha256' => $meta['sha256']];
            $this->idempotency->complete($subject['user_id'], $account['id'], $operation, $key, $result);
        } catch (AppError $e) {
            $this->recordMutationError($e, $subject['user_id'], $account['id'], $operation, $key);
            throw $e;
        } finally {
            if (is_string($apiKey)) sodium_memzero($apiKey);
            if (is_string($temp) && is_file($temp)) unlink($temp);
        }
        $this->cleanupUpload($uploads, $uploadId, $traceId);
        return $this->success($traceId, $alias, $operation, $result);
    }

    private function cleanupUpload(UploadStore $uploads, string $uploadId, string $traceId): void
    {
        try { $uploads->delete($uploadId); }
        catch (\Throwable $e) { SafeLogger::log('upload_cleanup_error', ['trace_id' => $traceId, 'error_code' => 'cleanup_pending']); }
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
        if (!(new UserSettings($this->pdo))->finalizeEnabled((int) $subject['user_id'])) {
            throw new AppError('finalize_disabled', 'Finalizing is disabled in your user settings at /accounts.', 403);
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
        $operation = $this->aliases->resolve('operations', Util::requireString($args, 'operation', 128), ['prepare_upload','upload_voucher','attach_to_voucher']);
        $source = $args['source'] ?? null;
        if (!is_array($source)) {
            throw new AppError('validation_error', 'source must be an object.', 400);
        }
        $accountAlias = Util::requireString($args, 'account', 96);
        $account = $this->accounts->getForUser($subject['user_id'], $accountAlias);
        if ($operation === 'prepare_upload') {
            $source = $this->aliases->normalizeParameters($source, ['filename','mime_type','size_bytes','sha256']);
            return $this->success($traceId, $accountAlias, $operation, (new UploadStore($this->pdo))->prepare($subject, $account, $source));
        }
        $source = $this->aliases->normalizeParameters($source, ['kind','filename','mime_type','content_base64','url','sha256','upload_id']);
        $source['kind'] = $this->aliases->enum(Util::requireString($source, 'kind', 16), ['base64','https','upload'], 'source.kind');
        $key = $this->validator->idempotencyKey($args['idempotency_key'] ?? null);
        $voucherId = null;
        if ($operation === 'attach_to_voucher') {
            $voucherId = $this->validator->uuid(Util::requireString($args, 'voucher_id', 64), 'voucher_id');
        }
        if ($source['kind'] === 'upload') {
            Util::assertKeys($source, ['kind','upload_id']);
            return $this->stagedFile($subject, $account, $accountAlias, $operation, $key, $voucherId, Util::requireString($source, 'upload_id', 36), $traceId);
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
                    throw new AppError('finalize_required', 'Blank and unchecked voucher fields cannot be changed through lexware_write. To add a missing document, use lexware_file with attach_to_voucher and the existing voucher_id; do not create another voucher or finalize merely to attach a file.', 409, false, [], 'For an already authorized receipt-processing task with an unambiguous file and voucher, prepare and PUT the file, call lexware_file attach_to_voucher with the existing voucher_id, then read the voucher to verify its files. No additional user confirmation is required for attachment. For bookkeeping field changes, follow the OCR and separately authorized finalization workflow.');
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
            if (!is_int($size) || $size < 1 || $size > UploadStore::MAX_BYTES) {
                throw new AppError('file_size_invalid', 'File must be between 1 and 4500000 bytes (4.5 MB).', 400);
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
        if (($parts['scheme'] ?? '') !== 'https' || !Config::remoteFileHostAllowed($host) || isset($parts['user']) || isset($parts['pass']) || (($parts['port'] ?? 443) !== 443)) {
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
        $received = 0;
        curl_setopt_array($ch, [CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$received): int {
            $received += strlen($chunk);
            if ($received > UploadStore::MAX_BYTES) return 0;
            return fwrite($handle, $chunk) ?: 0;
        }, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => UploadStore::MAX_BYTES, CURLOPT_RESOLVE => [$host . ':443:' . $pinnedIp]]);
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
        $text = in_array($action, ['prepare_upload','describe'], true) ? Util::jsonEncode($data) : "Lexware operation {$action} completed.";
        return ['resultType' => 'complete', 'content' => array_merge([['type' => 'text', 'text' => $text]], $contentExtra), 'structuredContent' => $structured, 'isError' => false];
    }

    private function definition(string $name, string $description, array $properties, array $required): array
    {
        $readOnly = in_array($name, ['lexware_search', 'lexware_get', 'lexware_describe'], true);
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
            'annotations' => ['readOnlyHint' => $readOnly, 'destructiveHint' => $destructive, 'idempotentHint' => $readOnly, 'openWorldHint' => true],
        ];
    }
}
