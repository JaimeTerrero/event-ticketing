<?php
declare(strict_types=1);

namespace Ticketing\Infrastructure;

use PDO;
use Ticketing\Application\AuthRepository;

final class AuthStore implements AuthRepository
{
    public function __construct(private readonly DatabaseConnection $connection)
    {
    }

    public function create(string $name, string $email, string $passwordHash): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash)
             VALUES (:name, :email, :password_hash)
             ON CONFLICT (email) DO NOTHING
             RETURNING id, name, email, created_at'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function findByEmail(string $email): array|false
    {
        $statement = $this->connection->pdo()->prepare('SELECT id, password_hash FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function storeSession(int $userId, string $tokenHash): void
    {
        $session = $this->connection->pdo()->prepare(
            "INSERT INTO user_sessions (token_hash, user_id, expires_at)
             VALUES (:token_hash, :user_id, now() + interval '24 hours')"
        );
        $session->execute([
            'token_hash' => $tokenHash,
            'user_id' => $userId,
        ]);
    }

    public function findActiveUser(string $tokenHash): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.created_at, u.role
             FROM user_sessions AS s
             JOIN users AS u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash AND s.expires_at > now()'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
