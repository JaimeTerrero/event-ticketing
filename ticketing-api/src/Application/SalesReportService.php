<?php
declare(strict_types=1);

namespace Ticketing\Application;

final class SalesReportService
{
    public function __construct(private readonly SalesReportRepository $repository)
    {
    }

    public function get(?int $eventId): array
    {
        if ($eventId === null) {
            return $this->repository->summary();
        }

        $report = $this->repository->forEvent($eventId);
        if ($report === false) {
            throw new ApplicationError(ErrorKind::NotFound, 'Evento no encontrado.');
        }

        return $report;
    }
}
