<?php
declare(strict_types=1);

namespace Ticketing\Application;

interface PurchaseRepository
{
    public function reserveAndRecord(int $userId, int $eventId, int $quantity): array|false;

    public function forUser(int $userId): array;
}
