<?php
declare(strict_types=1);

$counterFile = getenv('LEXMCP_FAKE_COUNTER');
if (is_string($counterFile) && $counterFile !== '') {
    $handle = fopen($counterFile, 'c+');
    if (is_resource($handle) && flock($handle, LOCK_EX)) {
        $current = stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) ((int) $current + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    if (is_resource($handle)) fclose($handle);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
header('Content-Type: application/json');

if (($query['simulate'] ?? '') === 'rate-limit') {
    http_response_code(429);
    header('Retry-After: 1');
    echo json_encode(['message' => 'rate limited']);
    return;
}
if (($query['simulate'] ?? '') === 'gateway-timeout') {
    http_response_code(504);
    echo json_encode(['message' => 'unknown outcome']);
    return;
}
if ($path === '/v1/profile') {
    echo json_encode(['organizationId' => '00000000-0000-4000-8000-000000000001', 'companyName' => 'Test GmbH']);
    return;
}
if ($path === '/v1/contacts' || $path === '/v1/articles' || $path === '/v1/voucherlist') {
    echo json_encode(['content' => [], 'number' => (int) ($query['page'] ?? 0), 'size' => (int) ($query['size'] ?? 50), 'totalPages' => 0, 'totalElements' => 0, 'first' => true, 'last' => true]);
    return;
}
if ($path === '/v1/files' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(202);
    echo json_encode(['id' => '00000000-0000-4000-8000-000000000010', 'voucherId' => '00000000-0000-4000-8000-000000000011']);
    return;
}
http_response_code(404);
echo json_encode(['message' => 'not found']);
