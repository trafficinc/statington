<?php

declare(strict_types=1);

namespace Statington\Server;

final class EventValidator
{
    private const MAX_TYPE_BYTES = 64;
    private const MAX_APP_BYTES = 128;
    private const MAX_ENVIRONMENT_BYTES = 128;
    private const MAX_REQUEST_ID_BYTES = 128;

    public function validate(array $event): array
    {
        if (!isset($event['type']) || !is_string($event['type']) || trim($event['type']) === '') {
            return ['ok' => false, 'error' => 'missing_type'];
        }

        if (strlen($event['type']) > self::MAX_TYPE_BYTES || preg_match('/^[a-zA-Z0-9_.-]+$/', $event['type']) !== 1) {
            return ['ok' => false, 'error' => 'invalid_type'];
        }

        foreach (['app' => self::MAX_APP_BYTES, 'environment' => self::MAX_ENVIRONMENT_BYTES] as $key => $limit) {
            if (isset($event[$key]) && (!is_scalar($event[$key]) || strlen((string) $event[$key]) > $limit)) {
                return ['ok' => false, 'error' => 'invalid_' . $key];
            }
        }

        $requestId = $event['request_id'] ?? ($event['payload']['request_id'] ?? null);
        if ($requestId !== null && (!is_scalar($requestId) || strlen((string) $requestId) > self::MAX_REQUEST_ID_BYTES)) {
            return ['ok' => false, 'error' => 'invalid_request_id'];
        }

        if (isset($event['payload']) && !is_array($event['payload'])) {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }

        return ['ok' => true];
    }
}
