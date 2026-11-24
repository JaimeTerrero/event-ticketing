<?php
declare(strict_types=1);

use Ticketing\Application\AuthService;
use Ticketing\Application\EventService;
use Ticketing\Application\PurchaseService;
use Ticketing\Application\SalesReportService;
use Ticketing\Http\Router;
use Ticketing\Infrastructure\AuthStore;
use Ticketing\Infrastructure\DatabaseConnection;
use Ticketing\Infrastructure\EventStore;
use Ticketing\Infrastructure\PurchaseStore;
use Ticketing\Infrastructure\SalesReportStore;

require dirname(__DIR__) . '/src/bootstrap.php';

$connection = new DatabaseConnection();
$router = new Router(
    new AuthService(new AuthStore($connection)),
    new EventService(new EventStore($connection)),
    new PurchaseService(new PurchaseStore($connection)),
    new SalesReportService(new SalesReportStore($connection))
);

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $headers['authorization'] ?? '');
