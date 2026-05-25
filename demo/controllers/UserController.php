<?php

declare(strict_types=1);

namespace Demo\Controllers;

use Demo\Services\FakeCache;
use Demo\Services\FakeDb;
use Statington\Statington;

final class UserController
{
    public function index(): string
    {
        Statington::span('auth_check', static function (): void {
            usleep(5000);
        });

        $cache = new FakeCache();
        $db = new FakeDb();
        $users = $cache->lookup('users.active') ?? $db->users();

        Statington::log('Fetched users', ['count' => count($users)]);

        $items = '';
        foreach ($users as $user) {
            $items .= '<li>' . htmlspecialchars($user['name'], ENT_QUOTES) . ' <small>' . htmlspecialchars($user['email'], ENT_QUOTES) . '</small></li>';
        }

        return '<h1>Users</h1><ul>' . $items . '</ul><p><a href="/">Back</a></p>';
    }

    public function databaseImpact(): string
    {
        $rows = (new FakeDb())->impactReport();
        Statington::log('Generated database impact demo', ['rows' => count($rows)]);

        $items = '';
        foreach ($rows as $row) {
            $items .= '<li>' . htmlspecialchars($row['name'], ENT_QUOTES) . ' <small>' . htmlspecialchars($row['email'], ENT_QUOTES) . ' - logins: ' . htmlspecialchars((string) $row['login_count'], ENT_QUOTES) . '</small></li>';
        }

        return '<h1>Database impact</h1><p>This route uses in-memory SQLite through <code>Statington::wrapPdo()</code>. Open this request in the Statington dashboard and check the Database Impact panel.</p><ul>' . $items . '</ul><p><a href="/">Back</a></p>';
    }

    public function complexSelect(): string
    {
        $rows = (new FakeDb())->complexSelectReport();
        Statington::log('Generated complex SELECT demo', ['rows' => count($rows)]);

        $items = '';
        foreach ($rows as $row) {
            $items .= '<li>' . htmlspecialchars($row['name'], ENT_QUOTES)
                . ' <small>' . htmlspecialchars($row['team_name'], ENT_QUOTES)
                . ' - paid orders: ' . htmlspecialchars((string) $row['paid_orders'], ENT_QUOTES)
                . ' - revenue: $' . htmlspecialchars((string) $row['revenue'], ENT_QUOTES)
                . '</small></li>';
        }

        return '<h1>Complex SELECT</h1><p>This route runs a joined, grouped SQLite SELECT with named bindings. Open the Statington dashboard and expand the SELECT query in Database Impact to see the SQL and bindings.</p><ul>' . $items . '</ul><p><a href="/">Back</a></p>';
    }
}
