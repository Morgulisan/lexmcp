<?php
declare(strict_types=1);

namespace LexMcp;

final class LexwareResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly mixed $data,
        public readonly string $contentType,
    ) {}
}

final class LexwareClient
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function request(array $account, string $apiKey, string $method, string $path, ?array $json = null, bool $binary = false): LexwareResponse
    {
        $method = strtoupper($method);
        $safe = $method === 'GET';
        $attempts = 0;
        while (true) {
            $attempts++;
            $this->limiter->acquire((string) $account['api_key_fingerprint']);
            try {
                $response = $this->execute($apiKey, $method, $path, $json, $binary);
            } catch (AppError $e) {
                if ($safe && $attempts < 3 && $e->errorCode === 'lexware_transport_error') {
                    $this->jitter($attempts);
                    continue;
                }
                throw $e;
            }
            if ($response->status === 429) {
                $seconds = $this->retryAfter($response->headers, $attempts);
                $this->limiter->block((string) $account['api_key_fingerprint'], $seconds);
                if ($attempts < 3) {
                    continue;
                }
            } elseif ($safe && $response->status >= 500 && $attempts < 3) {
                $this->jitter($attempts);
                continue;
            }
            if ($response->status < 200 || $response->status >= 300) {
                $retryable = $response->status === 429 || ($safe && $response->status >= 500);
                $message = $this->safeErrorMessage($response->data, $response->status);
                throw new AppError('lexware_api_error', $message, $response->status === 429 ? 503 : 502, $retryable, ['lexware_status' => $response->status]);
            }
            if ($safe && !$binary && $response->data === null) {
                if ($attempts < 3) {
                    $this->jitter($attempts);
                    continue;
                }
                throw new AppError('lexware_invalid_response', 'Lexware returned an invalid JSON response.', 502, true, ['lexware_status' => $response->status]);
            }
            return $response;
        }
    }

    public function upload(array $account, string $apiKey, string $path, string $filePath, string $filename, string $mimeType, bool $newVoucher): LexwareResponse
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $this->limiter->acquire((string) $account['api_key_fingerprint']);
            $headers = [];
            $ch = curl_init(Config::lexwareBaseUrl() . $path);
            $fields = ['file' => new \CURLFile($filePath, $mimeType, $filename)];
            if ($newVoucher) {
                $fields['type'] = 'voucher';
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => self::protocols(),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $error = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($error !== 0 || !is_string($body)) {
                if ($attempts < 3) {
                    $this->jitter($attempts);
                    continue;
                }
                throw new AppError('lexware_transport_error', 'The Lexware upload result is unknown.', 502, false);
            }
            $data = $body === '' ? null : $this->decodeBody($body, $contentType);
            $response = new LexwareResponse($status, $headers, $data, $contentType);
            if ($status === 429) {
                $this->limiter->block((string) $account['api_key_fingerprint'], $this->retryAfter($headers, $attempts));
                if ($attempts < 3) {
                    continue;
                }
            }
            if ($status < 200 || $status >= 300) {
                throw new AppError('lexware_api_error', $this->safeErrorMessage($data, $status), $status === 429 ? 503 : 502, $status === 429, ['lexware_status' => $status]);
            }
            if (!is_array($data)) {
                throw new AppError('lexware_uncertain_response', 'Lexware accepted the upload but returned no usable operation result.', 502, false, ['lexware_status' => $status]);
            }
            return $response;
        }
    }

    private function execute(string $apiKey, string $method, string $path, ?array $json, bool $binary): LexwareResponse
    {
        $headers = [];
        $requestHeaders = ['Authorization: Bearer ' . $apiKey, 'Accept: ' . ($binary ? '*/*' : 'application/json')];
        $ch = curl_init(Config::lexwareBaseUrl() . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => self::protocols(), CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ];
        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = Util::jsonEncode($json);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($error !== 0 || !is_string($body)) {
            throw new AppError('lexware_transport_error', 'Lexware could not be reached; the outcome may be unknown.', 502, false);
        }
        return new LexwareResponse($status, $headers, $binary && $status >= 200 && $status < 300 ? $body : $this->decodeBody($body, $contentType), $contentType);
    }

    private function decodeBody(string $body, string $contentType): mixed
    {
        if ($body === '') {
            return null;
        }
        if (str_contains(strtolower($contentType), 'json') || str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
            try {
                return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        return null;
    }

    private function safeErrorMessage(mixed $data, int $status): string
    {
        return "Lexware rejected the request with HTTP {$status}.";
    }

    private function retryAfter(array $headers, int $attempt): int
    {
        $value = $headers['retry-after'] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            return max(1, min((int) $value, 300));
        }
        if (is_string($value) && ($time = strtotime($value)) !== false) {
            return max(1, min($time - time(), 300));
        }
        return min(2 ** $attempt, 15);
    }

    private function jitter(int $attempt): void
    {
        usleep(random_int(0, min(15000000, 500000 * (2 ** $attempt))));
    }

    private static function protocols(): int
    {
        return Config::production() ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP;
    }
}
