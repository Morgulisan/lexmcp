<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/bootstrap.php';
$pdo=mcpAuthDatabase();
// Keep replay markers until their original grant can no longer be used.
foreach (['oauth_requests','oauth_codes','oauth_tokens','web_sessions'] as $table) {
    $pdo->exec("DELETE FROM mpauth_$table WHERE expires_at < NOW(6) - INTERVAL 1 DAY");
}
$pdo->exec('DELETE FROM mpauth_limits WHERE expires_at < UNIX_TIMESTAMP()');
echo "Expired auth state removed.\n";
