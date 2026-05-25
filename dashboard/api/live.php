<?php

declare(strict_types=1);

header('Content-Type: application/json');
$since = filter_var($_GET['since'] ?? 0, FILTER_VALIDATE_INT);
echo json_encode($store->live($since === false ? 0 : $since), JSON_UNESCAPED_SLASHES);
