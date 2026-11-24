<?php
declare(strict_types=1);

namespace Ticketing\Infrastructure;

use PDO;
use Ticketing\Application\SalesReportRepository;

final class SalesReportStore implements SalesReportRepository
{
    public function __construct(private readonly DatabaseConnection $connection)
    {
    }

    public function summary(): array
    {
        return $this->connection->pdo()->query(
            "SELECT COUNT(*) AS purchase_count,
                    COALESCE(SUM(quantity), 0) AS tickets_sold,
                    COALESCE(SUM(total_amount), 0.00)::text AS total_revenue
             FROM ticket_sales
             WHERE status = 'confirmed'"
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function forEvent(int $id): array|false
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT e.id AS event_id, e.name AS event_name,
                    COUNT(s.id) AS purchase_count,
                    COALESCE(SUM(s.quantity), 0) AS tickets_sold,
                    COALESCE(SUM(s.total_amount), 0.00)::text AS total_revenue
             FROM events AS e
             LEFT JOIN ticket_sales AS s
                 ON s.event_id = e.id AND s.status = 'confirmed'
             WHERE e.id = :id
             GROUP BY e.id, e.name"
        );
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
