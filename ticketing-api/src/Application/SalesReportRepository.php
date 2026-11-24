<?php
declare(strict_types=1);

namespace Ticketing\Application;

interface SalesReportRepository
{
    public function summary(): array;

    public function forEvent(int $id): array|false;
}
