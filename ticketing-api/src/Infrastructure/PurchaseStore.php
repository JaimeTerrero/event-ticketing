<?php
declare(strict_types=1);

namespace Ticketing\Infrastructure;

use PDO;
use Ticketing\Application\PurchaseRepository;

final class PurchaseStore implements PurchaseRepository
{
    public function __construct(private readonly DatabaseConnection $connection)
    {
    }

    public function reserveAndRecord(int $userId, int $eventId, int $quantity): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            "WITH requested AS (
                 SELECT CAST(:quantity AS integer) AS quantity
             ), reserved AS (
                 UPDATE events AS e
                 SET available_tickets = e.available_tickets - r.quantity
                 FROM requested AS r
                 WHERE e.id = :event_id
                   AND e.status = 'published'
                   AND e.event_date > clock_timestamp()
                   AND e.available_tickets >= r.quantity
                 RETURNING e.id, r.quantity, e.ticket_price
             )
             INSERT INTO ticket_sales (user_id, event_id, quantity, total_amount)
             SELECT :user_id, id, quantity, ticket_price * quantity
             FROM reserved
             RETURNING id, event_id, quantity, total_amount, purchase_date, status"
        );
        $statement->execute([
            'event_id' => $eventId,
            'quantity' => $quantity,
            'user_id' => $userId,
        ]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function forUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT s.id, s.event_id, e.name AS event_name, s.quantity,
                    s.total_amount, s.purchase_date, s.status
             FROM ticket_sales AS s
             JOIN events AS e ON e.id = s.event_id
             WHERE s.user_id = :user_id
             ORDER BY s.purchase_date DESC, s.id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
