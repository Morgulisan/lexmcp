<?php
declare(strict_types=1);

namespace LexMcp;

/** Authorization belongs to LexMCP; file storage and expiration belong to MST. */
final class UploadStore
{
    public const MAX_BYTES = 4500000;
    public const MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'application/xml'];

    public function __construct(private readonly \PDO $pdo) {}

    private function storage(): \Mst\FileTransfer\Storage
    {
        $include = Config::fileTransferInclude();
        if (!is_file($include)) throw new AppError('upload_storage_unavailable', 'Install the shared MST FileTransfer component and configure LEXMCP_FILE_TRANSFER_INCLUDE.', 503);
        require_once $include;
        return new \Mst\FileTransfer\Storage(Config::dataPath() . '/uploads', self::MIME_TYPES);
    }

    public function prepare(array $subject, array $account, array $meta): array
    {
        $filename = basename(str_replace('\\', '/', Util::requireString($meta, 'filename', 255)));
        if ($filename === '' || in_array($filename, ['.', '..'], true) || preg_match('/[\x00-\x1f\x7f]/', $filename)) throw new AppError('validation_error', 'filename must be a printable file name.', 400, false, ['field' => 'source.filename']);
        $mime = Util::requireString($meta, 'mime_type', 128);
        if (!in_array($mime, self::MIME_TYPES, true)) throw new AppError('unknown_value', 'source.mime_type must be one of: ' . implode(', ', self::MIME_TYPES), 400, false, ['field' => 'source.mime_type', 'allowed' => self::MIME_TYPES]);
        $size = $meta['size_bytes'] ?? null;
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) throw new AppError('file_size_invalid', 'source.size_bytes must be between 1 and 4500000 (4.5 MB).', 400, false, ['field' => 'source.size_bytes']);
        $hash = strtolower(Util::requireString($meta, 'sha256', 64));
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new AppError('validation_error', 'source.sha256 must contain 64 hexadecimal characters.', 400, false, ['field' => 'source.sha256']);
        $this->checkConnection($subject);
        $this->shared(fn() => $this->storage()->cleanup());
        // Unused tickets outlive the latest possible file expiry before being removed.
        $this->pdo->prepare('DELETE FROM lxmcp_uploads WHERE binding_hash IS NULL AND expires_at<?')->execute([time() - 3660]);
        $id = Util::uuid();
        $token = bin2hex(random_bytes(32));
        $expires = time() + 900;
        $meta = ['filename' => $filename, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $hash];
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_uploads(id,user_id,connection_id,account_id,account_alias,token_hash,metadata_json,expires_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $subject['user_id'], $subject['connection_id'], $account['id'], $account['alias'], hash('sha256', $token), Util::jsonEncode($meta), $expires]);
        return ['upload_id' => $id, 'url' => Config::publicUrl() . '/uploads/' . $id, 'method' => 'PUT', 'headers' => ['X-Upload-Token' => $token, 'Content-Type' => $mime], 'expires_at' => gmdate('c', $expires), 'max_size_bytes' => self::MAX_BYTES];
    }

    private function row(string $id, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new AppError('upload_not_found', 'Upload not found.', 404);
        $stmt = $this->pdo->prepare('SELECT * FROM lxmcp_uploads WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new AppError('upload_not_found', 'Upload not found.', 404);
        $row['metadata'] = Util::jsonDecode($row['metadata_json']);
        return $row;
    }

    private function checkConnection(array $subject): void
    {
        if (!is_string($subject['connection_id'] ?? null)) throw new AppError('invalid_token', 'A current MCP connection is required.', 401);
        $scopes = (new ConnectionStore($this->pdo))->allowedScopes($subject['connection_id'], (int) $subject['user_id']);
        if (!in_array('lexware:write', $scopes, true)) throw new AppError('insufficient_scope', 'This connection no longer permits file uploads.', 403, false, ['required_scope' => 'lexware:write']);
        $active = $this->pdo->prepare('SELECT 1 FROM lxmcp_oauth_tokens WHERE family_id=? AND user_id=? AND revoked_at IS NULL AND expires_at>NOW(6) LIMIT 1');
        $active->execute([$subject['connection_id'], (int) $subject['user_id']]);
        if ($active->fetchColumn() === false) throw new AppError('invalid_token', 'The MCP connection has no active authorization.', 401);
    }

    /** The scoped upload token is the credential here; the OAuth bearer never enters the shell. */
    public function receive(string $id, string $token, mixed $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->row($id, true);
            if ($token === '' || !hash_equals($row['token_hash'], hash('sha256', $token))) throw new AppError('invalid_upload_token', 'Invalid upload token.', 403);
            if ((int) $row['expires_at'] <= time()) throw new AppError('upload_expired', 'Upload authorization has expired. Prepare a new upload.', 410);
            if ($row['binding_hash'] !== null) throw new AppError('upload_bound', 'This upload is already bound to a voucher operation.', 409);
            $this->checkConnection($row);
            $account = (new AccountStore($this->pdo))->getForUser((int) $row['user_id'], $row['account_alias']);
            if ($account['id'] !== $row['account_id']) throw new AppError('upload_account_mismatch', 'Upload account is no longer available.', 403);
            $meta = $row['metadata'];
            $stored = $this->shared(function () use ($id, $input, $meta): array {
                $storage = $this->storage();
                $storage->cleanup();
                return $storage->put(hash('sha256', $id), $input, $meta['filename'], $meta['mime_type'], $meta['sha256'], $meta['size_bytes']);
            });
            $this->pdo->commit();
            return ['upload_id' => $id, 'ready' => true, 'sha256' => $stored['sha256'], 'size_bytes' => $stored['bytes'], 'expires_at' => gmdate('c', $stored['expires_at'])];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function claim(string $id, array $subject, array $account, string $operation, string $key, ?string $voucherId): array
    {
        $this->checkConnection($subject);
        $binding = hash('sha256', Util::canonicalJson([$operation, $key, $voucherId]));
        $this->pdo->beginTransaction();
        try {
            $row = $this->row($id, true);
            if ((int) $row['user_id'] !== (int) $subject['user_id'] || $row['connection_id'] !== $subject['connection_id'] || $row['account_id'] !== $account['id']) throw new AppError('upload_not_found', 'Upload not found for this connection and account.', 404);
            if ($row['binding_hash'] !== null && !hash_equals($row['binding_hash'], $binding)) throw new AppError('upload_already_used', 'Upload is bound to a different operation or idempotency key.', 409);
            if ($row['binding_hash'] === null) {
                // An unreceived or expired file cannot reserve a voucher operation.
                $this->shared(fn() => $this->storage()->withFile(hash('sha256', $id), static fn($path, $meta) => $meta));
                $stmt = $this->pdo->prepare('UPDATE lxmcp_uploads SET binding_hash=? WHERE id=?');
                $stmt->execute([$binding, $id]);
            }
            $this->pdo->commit();
            return $row['metadata'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function copyTo(string $id, string $target): void
    {
        $this->shared(fn() => $this->storage()->withFile(hash('sha256', $id), static function (string $path, array $meta) use ($target): void {
            if (!copy($path, $target)) throw new AppError('temporary_storage_error', 'Cannot read uploaded file.', 500);
            if (!hash_equals($meta['sha256'], hash_file('sha256', $target))) throw new AppError('file_checksum_mismatch', 'Stored file checksum does not match.', 400);
        }));
    }

    public function delete(string $id): void
    {
        $this->shared(fn() => $this->storage()->delete(hash('sha256', $id)));
    }

    private function shared(callable $callback): mixed
    {
        try { return $callback(); }
        catch (\Mst\FileTransfer\TransferError $e) { throw new AppError($e->errorCode, $e->getMessage(), $e->status); }
    }
}
