<?php

declare(strict_types=1);

namespace Demo\Controllers;

use Statington\Statington;

final class AuthController
{
    public function loginFail(): string
    {
        Statington::log('User login failed', [
            'user_id' => 42,
            'reason' => 'invalid_password',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'password' => 'correct-horse-battery-staple',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'Bearer demo-token',
        ], 'warning');

        http_response_code(401);

        return '<h1>Login failed</h1><p>The dashboard will show a warning log with redacted sensitive context.</p><p><a href="/">Back</a></p>';
    }

    public function redacted(): string
    {
        Statington::span('load_redaction_demo', static function (): void {
            usleep(8000);
        });

        Statington::log('Captured sensitive demo context', [
            'user_id' => 42,
            'password' => 'super-secret-password',
            'token' => $_GET['token'] ?? 'query-token',
            'api_key' => $_GET['api_key'] ?? 'query-api-key',
            'private_key' => '-----BEGIN PRIVATE KEY-----demo-----END PRIVATE KEY-----',
            'credit_card' => '4111111111111111',
            'profile' => [
                'ssn' => '123-45-6789',
                'safe_note' => 'This field should stay visible.',
            ],
        ]);

        return $this->page('Redacted Data Demo', '<p>This route logs sensitive context and captures sensitive query params. Open the dashboard request detail and look for <code>[REDACTED]</code>.</p>
            <form method="post" action="/redacted-submit">
                <label>Email <input name="email" value="demo@example.test"></label>
                <label>Password <input name="password" value="super-secret-password"></label>
                <label>API token <input name="access_token" value="access-token-123"></label>
                <label>Credit card <input name="card_number" value="4111111111111111"></label>
                <button type="submit">Submit sensitive form</button>
            </form>
            <p><a href="/">Back</a></p>');
    }

    public function redactedSubmit(): string
    {
        Statington::log('Submitted sensitive demo form', [
            'post' => $_POST,
            'cookie' => $_SERVER['HTTP_COOKIE'] ?? 'session=demo-cookie',
            'safe_field' => 'visible value',
        ], 'info');

        return $this->page('Sensitive Form Submitted', '<p>The form POST body and log context should show redacted password, token, card, and cookie fields in the dashboard.</p><p><a href="/redacted">Back</a></p>');
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title><style>
            body{font:15px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#17202a}
            main{width:min(720px,calc(100vw - 32px));margin:48px auto;background:#fff;border:1px solid #dfe5ec;border-radius:8px;padding:24px}
            h1{margin:0 0 12px}
            p{color:#657386}
            form{display:grid;gap:12px;margin-top:18px}
            label{display:grid;gap:5px;font-weight:700}
            input{font:inherit;padding:9px 10px;border:1px solid #cbd5df;border-radius:6px}
            button{justify-self:start;padding:9px 12px;border:0;border-radius:6px;background:#0f8b8d;color:#fff;font-weight:700}
            code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
        </style></head><body><main><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>' . $body . '</main></body></html>';
    }
}
