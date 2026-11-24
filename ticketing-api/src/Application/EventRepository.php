<?php
declare(strict_types=1);

namespace Ticketing\Application;

interface EventRepository
{
    public function create(string $name, string $description, string $date, int $total, string $price): array;

    public function published(): array;

    public function findPublished(int $id): array|false;

    public function changeStatus(int $id, string $status): array|false;

    public function update(int $id, string $name, string $description, string $date, int $total, string $price): array|false;

    public function exists(int $id): bool;
}
