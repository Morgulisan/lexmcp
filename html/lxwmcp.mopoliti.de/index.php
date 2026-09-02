<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/lxwmcp/bootstrap.php';

LexMcp\Application::run($lxmcpPdo);
