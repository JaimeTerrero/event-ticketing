<?php
declare(strict_types=1);

namespace Ticketing\Application;

interface AuthRepository
{
    public function create(string $name, string $email, string $passwordHash): array|false;

    public function findByEmail(string $email): array|false;

    public function storeSession(int $userId, string $tokenHash): void;

    public function findActiveUser(string $tokenHash): array|false;
}
