<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/includes/mcp-auth/bootstrap.php';
(new MopolitiAuth\Application())->run();
