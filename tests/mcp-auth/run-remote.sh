#!/bin/sh
set -eu
cd /opt/mcp-oauth-work/stage
docker build -q -t mcp-auth-test-php -f tests/mcp-auth/Dockerfile .
docker run --rm -v "$PWD:/src:ro" mcp-auth-test-php sh -c 'find /src/includes/mcp-auth /src/html/auth.mopoliti.de /src/tests/mcp-auth -name "*.php" -exec php -l {} \;'
docker network create mcp-auth-test >/dev/null
trap 'docker rm -f mcp-auth-test-db >/dev/null 2>&1; docker network rm mcp-auth-test >/dev/null 2>&1' EXIT
docker run -d --name mcp-auth-test-db --network mcp-auth-test --tmpfs /var/lib/mysql -e MARIADB_ROOT_PASSWORD=test-only-password -e MARIADB_DATABASE=mcp_auth_test mariadb:11 >/dev/null
for i in $(seq 1 60); do
 if docker exec mcp-auth-test-db mariadb-admin ping --protocol=tcp -h127.0.0.1 -uroot -ptest-only-password --silent >/dev/null 2>&1; then break; fi
 sleep 1
done
docker run --rm --network mcp-auth-test -v "$PWD:/src:ro" mcp-auth-test-php php /src/tests/mcp-auth/auth.php
docker run --rm -v "$PWD:/src:ro" -w /src node:22-slim node --test tests/mcp-auth/gateway.test.mjs
