<?php
declare(strict_types=1);

namespace Ticketing\Application;

use stdClass;

final class PurchaseService
{
    public function __construct(private readonly PurchaseRepository $repository)
    {
    }

    public function purchase(int $userId, stdClass $body): array
    {
        $eventId = $body->event_id ?? null;
        $quantity = $body->quantity ?? null;

        if (!is_int($eventId) || $eventId < 1 ||
            !is_int($quantity) || $quantity < 1 || $quantity > 2147483647) {
            throw new ApplicationError(ErrorKind::Validation, 'event_id y quantity deben ser enteros positivos.');
        }

        $sale = $this->repository->reserveAndRecord($userId, $eventId, $quantity);
        if ($sale === false) {
            throw new ApplicationError(ErrorKind::Conflict, 'El evento o la cantidad de entradas solicitada no están disponibles.');
        }

        return $sale;
    }

    public function history(int $userId): array
    {
        return $this->repository->forUser($userId);
    }
}
