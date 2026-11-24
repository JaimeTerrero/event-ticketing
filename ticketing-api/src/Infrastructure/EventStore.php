<?php
declare(strict_types=1);

namespace Ticketing\Infrastructure;

use PDO;
use Ticketing\Application\EventRepository;

final class EventStore implements EventRepository
{
    public function __construct(private readonly DatabaseConnection $connection)
    {
    }

    public function create(string $name, string $description, string $date, int $total, string $price): array
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO events (name, description, event_date, total_tickets, available_tickets, ticket_price)
             VALUES (:name, :description, :event_date, :total_tickets, :available_tickets, :ticket_price)
             RETURNING id, name, description, event_date, total_tickets, available_tickets, ticket_price, status'
        );
        $statement->execute([
            'name' => $name,
            'description' => $description,
            'event_date' => $date,
            'total_tickets' => $total,
            'available_tickets' => $total,
            'ticket_price' => $price,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function published(): array
    {
        $events = $this->connection->pdo()->query(
            "SELECT id, name, description, event_date, total_tickets,
                    available_tickets, ticket_price, status
             FROM events
             WHERE status = 'published'
             ORDER BY event_date, id"
        );
        return $events->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPublished(int $id): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT id, name, description, event_date, total_tickets,
                    available_tickets, ticket_price, status
             FROM events
             WHERE id = :id AND status = 'published'"
        );
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function changeStatus(int $id, string $status): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE events
             SET status = requested.status
             FROM (SELECT CAST(:status AS text) AS status) AS requested
             WHERE events.id = :id
               AND (
                   events.status = requested.status
                   OR (events.status = 'draft' AND requested.status = 'published'
                       AND events.event_date > now())
                   OR (events.status IN ('draft', 'published') AND requested.status = 'cancelled')
               )
             RETURNING events.id, events.status"
        );
        $statement->execute(['id' => $id, 'status' => $status]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function update(int $id, string $name, string $description, string $date, int $total, string $price): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE events AS e
             SET name = :name,
                 description = :description,
                 event_date = :event_date,
                 ticket_price = :ticket_price,
                 total_tickets = requested.total,
                 available_tickets = requested.total - (e.total_tickets - e.available_tickets)
             FROM (SELECT CAST(:total AS integer) AS total) AS requested
             WHERE e.id = :id
               AND e.status <> 'cancelled'
               AND requested.total >= e.total_tickets - e.available_tickets
             RETURNING e.id, e.name, e.description, e.event_date,
                       e.total_tickets, e.available_tickets, e.ticket_price, e.status"
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'event_date' => $date,
            'total' => $total,
            'ticket_price' => $price,
        ]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function exists(int $id): bool
    {
        $exists = $this->connection->pdo()->prepare('SELECT 1 FROM events WHERE id = :id');
        $exists->execute(['id' => $id]);
        return $exists->fetchColumn() !== false;
    }
}
