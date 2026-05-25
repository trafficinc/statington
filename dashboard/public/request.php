<?php

declare(strict_types=1);

$requestId = (string) ($_GET['id'] ?? '');
$detail = $requestId !== '' ? $store->requestDetail($requestId) : null;
$navigation = $requestId !== '' ? $store->requestNavigation($requestId) : ['previous' => null, 'next' => null];
$title = $detail ? ($detail['request']['method'] . ' ' . $detail['request']['path']) : 'Request not found';
$view = dirname(__DIR__) . '/views/detail.php';
require dirname(__DIR__) . '/views/layout.php';
