<?php
declare(strict_types=1);

namespace Todo;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

final class Support
{
    public static function id(): string { return self::randomId(10); }
    public static function shortId(): string { return self::randomId(8); }
    private static function randomId(int $length): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $id = '';
        for ($i = 0; $i < $length; $i++) $id .= $alphabet[random_int(0, 61)];
        return $id;
    }
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function decode(string $value): array { return json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
    public static function text(array $input, string $key, int $max, bool $empty = false): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || (!$empty && trim($value) === '')) {
            throw new Failure('invalid_field', "Ungültiges Feld: {$key}");
        }
        return $value;
    }
    public static function number(array $input, string $key, int $min, int $max): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) throw new Failure('invalid_field', "Ungültige Zahl: {$key}");
        return $value;
    }
    public static function strings(mixed $value, int $limit = 50): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) throw new Failure('invalid_list', 'Ungültige Liste.');
        foreach ($value as $item) self::text(['item' => $item], 'item', 160);
        return array_values(array_unique($value));
    }
    public static function noSecrets(string $value): void
    {
        if (preg_match('/(?:-----BEGIN .*PRIVATE KEY|\bBearer\s+[A-Za-z0-9._~+\/-]{12,}|\b(?:password|passwort|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|client[_ -]?secret)\s*[:=]\s*\S+|\bsk-[A-Za-z0-9_-]{16,})/i', $value)) {
            throw new Failure('secret_detected', 'Zugangsdaten dürfen hier nicht gespeichert werden.');
        }
    }
    public static function url(string $value): string
    {
        $parts = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new Failure('invalid_url', 'Eine HTTPS-Adresse ohne Zugangsdaten ist erforderlich.');
        }
        return $value;
    }
}

final readonly class Actor
{
    public function __construct(public int $user, public ?string $agent = null, public array $projects = [], public array $scopes = [], public array $capabilities = [], public ?string $client = null, public ?string $run = null) {}
    public function isUser(): bool { return $this->agent === null; }
    public function key(): string { return $this->agent ?? 'user:' . $this->user; }
    public function project(string $id): void
    {
        if (!$this->isUser() && !in_array($id, $this->projects, true)) throw new Failure('forbidden', 'Kein Projektzugriff.', 403);
    }
    public function scope(string $scope): void
    {
        if (!$this->isUser() && !in_array($scope, $this->scopes, true)) throw new Failure('insufficient_scope', 'Fehlende Berechtigung: ' . $scope, 403);
    }
    public function userOnly(): void
    {
        if (!$this->isUser()) throw new Failure('user_only', 'Diese Aktion ist nur über die Weboberfläche erlaubt.', 403);
    }
}
