<?php

declare(strict_types=1);

namespace Statington\Server;

final class EventController
{
    public function __construct(private RequestStore $store)
    {
    }

    public function receive(): void
    {
        header('Content-Type: application/json');

        $raw = (string) file_get_contents('php://input');
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_json']);
            return;
        }

        $validation = (new EventValidator())->validate($event);
        if (($validation['ok'] ?? false) !== true) {
            http_response_code(422);
            echo json_encode($validation);
            return;
        }

        try {
            $id = $this->store->storeEvent($event);
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (\Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
}
