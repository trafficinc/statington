<?php

declare(strict_types=1);

header('Content-Type: application/json');
$requestId = (string) ($_GET['id'] ?? '');
$detail = $requestId !== '' ? $store->requestDetail($requestId) : null;
if (!$detail) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    return;
}

echo json_encode($detail, JSON_UNESCAPED_SLASHES);
