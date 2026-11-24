<?php
declare(strict_types=1);

namespace Ticketing\Infrastructure;

use PDO;

final class DatabaseConnection
{
    private ?PDO $pdo = null;

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            'pgsql:host=db;port=5432;dbname=ticketing',
            'ticketing',
            getenv('POSTGRES_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
