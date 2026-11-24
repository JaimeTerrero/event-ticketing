<?php
declare(strict_types=1);

namespace Ticketing\Application;

use DateTimeImmutable;
use stdClass;

final class EventService
{
    public function __construct(private readonly EventRepository $repository)
    {
    }

    public function create(stdClass $body): array
    {
        return $this->repository->create(...$this->validate($body));
    }

    public function list(): array
    {
        return $this->repository->published();
    }

    public function get(int $id): array
    {
        $event = $this->repository->findPublished($id);
        if ($event === false) {
            throw new ApplicationError(ErrorKind::NotFound, 'Evento no encontrado.');
        }

        return $event;
    }

    public function changeStatus(int $id, stdClass $body): array
    {
        $status = $body->status ?? null;
        if (!in_array($status, ['published', 'cancelled'], true)) {
            throw new ApplicationError(ErrorKind::Validation, 'El estado debe ser published o cancelled.');
        }

        $event = $this->repository->changeStatus($id, $status);
        if ($event !== false) {
            return $event;
        }

        if (!$this->repository->exists($id)) {
            throw new ApplicationError(ErrorKind::NotFound, 'Evento no encontrado.');
        }

        throw new ApplicationError(ErrorKind::Conflict, 'No se permite este cambio de estado.');
    }

    public function update(int $id, stdClass $body): array
    {
        $event = $this->repository->update($id, ...$this->validate($body));
        if ($event !== false) {
            return $event;
        }

        if (!$this->repository->exists($id)) {
            throw new ApplicationError(ErrorKind::NotFound, 'Evento no encontrado.');
        }

        throw new ApplicationError(ErrorKind::Conflict, 'El evento está cancelado o la capacidad es menor que la cantidad de entradas vendidas.');
    }

    private function validate(stdClass $body): array
    {
        $name = is_string($body->name ?? null) ? trim($body->name) : '';
        $description = is_string($body->description ?? null) ? trim($body->description) : '';
        $total = $body->total_tickets ?? null;
        $price = $body->ticket_price ?? null;
        $date = $body->event_date ?? null;

        if ($name === '' || strlen($name) > 200 ||
            $description === '' || strlen($description) > 5000 ||
            !is_int($total) || $total < 1 || $total > 2147483647 ||
            !is_string($price) || !preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/D', $price) ||
            $price === '0.00') {
            throw new ApplicationError(ErrorKind::Validation, 'Ingresa un nombre, una descripción, una cantidad de entradas mayor que cero y un precio positivo con dos decimales.');
        }

        $eventDate = is_string($date) && !str_contains($date, "\0")
            ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $date)
            : false;

        if ($eventDate === false || $eventDate->format('Y-m-d\TH:i:sP') !== $date ||
            $eventDate <= new DateTimeImmutable()) {
            throw new ApplicationError(ErrorKind::Validation, 'Ingresa una fecha futura en event_date con un desfase horario numérico respecto de UTC.');
        }

        return [$name, $description, $date, $total, $price];
    }
}
