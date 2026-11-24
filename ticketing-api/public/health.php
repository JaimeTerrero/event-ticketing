<?php
declare(strict_types=1);

use Ticketing\Http\Json;
use Ticketing\Infrastructure\DatabaseConnection;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    (new DatabaseConnection())->pdo()->query('SELECT 1');
    Json::respond(200, ['status' => 'ok']);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    Json::respond(503, ['status' => 'unavailable', 'error' => 'Base de datos no disponible.']);
}
