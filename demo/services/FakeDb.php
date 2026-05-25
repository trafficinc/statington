<?php

declare(strict_types=1);

namespace Demo\Services;

use PDO;
use Statington\Database\PdoProxy;
use Statington\Statington;

final class FakeDb
{
    private PdoProxy $pdo;

    public function __construct()
    {
        $this->pdo = Statington::wrapPdo(new PDO('sqlite::memory:'));
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, login_count INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, team_id INTEGER NOT NULL, total REAL NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
    }

    public function users(): array
    {
        return Statington::span('db_query', function (): array {
            $this->seedUsers();

            $statement = $this->pdo->prepare('UPDATE users SET login_count = login_count + 1 WHERE email = :email');
            $statement->execute(['email' => 'ada@example.test']);

            $result = $this->pdo->query('SELECT id, name, email, login_count FROM users ORDER BY id ASC');

            return $result->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function impactReport(): array
    {
        return Statington::span('db_impact_report', function (): array {
            $this->seedUsers();

            $insert = $this->pdo->prepare('INSERT INTO users (name, email, login_count) VALUES (:name, :email, :login_count)');
            $insert->execute([
                'name' => 'Margaret Hamilton',
                'email' => 'margaret@example.test',
                'login_count' => 1,
            ]);

            $update = $this->pdo->prepare('UPDATE users SET login_count = login_count + :amount WHERE email = :email');
            $update->execute([
                'amount' => 3,
                'email' => 'grace@example.test',
            ]);

            $select = $this->pdo->query('SELECT id, name, email, login_count FROM users ORDER BY login_count DESC');

            return $select->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function complexSelectReport(): array
    {
        return Statington::span('complex_select_report', function (): array {
            $this->seedUsers();
            $this->seedTeamsAndOrders();

            $statement = $this->pdo->prepare(
                'SELECT u.id,
                        u.name,
                        u.email,
                        t.name AS team_name,
                        COUNT(o.id) AS paid_orders,
                        SUM(o.total) AS revenue
                 FROM users u
                 INNER JOIN orders o ON o.user_id = u.id
                 INNER JOIN teams t ON t.id = o.team_id
                 WHERE o.status = :status
                   AND o.total >= :minimum_total
                   AND u.email LIKE :email_pattern
                 GROUP BY u.id, u.name, u.email, t.name
                 HAVING revenue >= :minimum_revenue
                 ORDER BY revenue DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':status', 'paid');
            $statement->bindValue(':minimum_total', 50);
            $statement->bindValue(':email_pattern', '%@example.test');
            $statement->bindValue(':minimum_revenue', 100);
            $statement->bindValue(':limit', 10, PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    private function seedUsers(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO users (name, email, login_count) VALUES (:name, :email, :login_count)');
        foreach ([
            ['Ada Lovelace', 'ada@example.test', 2],
            ['Grace Hopper', 'grace@example.test', 5],
            ['Katherine Johnson', 'katherine@example.test', 1],
        ] as [$name, $email, $loginCount]) {
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'login_count' => $loginCount,
            ]);
        }
    }

    private function seedTeamsAndOrders(): void
    {
        $teamInsert = $this->pdo->prepare('INSERT INTO teams (name) VALUES (:name)');
        foreach (['Core', 'Research'] as $team) {
            $teamInsert->execute(['name' => $team]);
        }

        $orderInsert = $this->pdo->prepare('INSERT INTO orders (user_id, team_id, total, status, created_at) VALUES (:user_id, :team_id, :total, :status, :created_at)');
        foreach ([
            [1, 1, 120.50, 'paid', '2026-05-20 10:00:00'],
            [1, 1, 48.00, 'paid', '2026-05-21 10:00:00'],
            [2, 2, 340.00, 'paid', '2026-05-21 11:00:00'],
            [2, 2, 15.00, 'refunded', '2026-05-22 12:00:00'],
            [3, 1, 88.25, 'paid', '2026-05-22 13:00:00'],
            [3, 1, 22.25, 'paid', '2026-05-22 14:00:00'],
        ] as [$userId, $teamId, $total, $status, $createdAt]) {
            $orderInsert->execute([
                'user_id' => $userId,
                'team_id' => $teamId,
                'total' => $total,
                'status' => $status,
                'created_at' => $createdAt,
            ]);
        }
    }
}
