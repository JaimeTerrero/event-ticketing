<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $db = new PDO(
        'pgsql:host=db;port=5432;dbname=ticketing',
        'ticketing',
        getenv('POSTGRES_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $db->query('SELECT 1');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'error' => 'Base de datos no disponible.']);
}
