<?php
declare(strict_types=1);

namespace Ticketing\Http;

use Ticketing\Application\ApplicationError;
use Ticketing\Application\AuthService;
use Ticketing\Application\ErrorKind;
use Ticketing\Application\EventService;
use Ticketing\Application\PurchaseService;
use Ticketing\Application\SalesReportService;
use Throwable;

final class Router
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly EventService $events,
        private readonly PurchaseService $purchases,
        private readonly SalesReportService $reports
    ) {
    }

    public function dispatch(string $uri, string $method, string $authorization): never
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $eventRoute = preg_match('~^/events/([1-9][0-9]*)(/availability|/status)?$~D', (string) $path, $matches) === 1;
        $statusRoute = $eventRoute && ($matches[2] ?? '') === '/status';
        $salesRoute = preg_match('~^/reports/events/([1-9][0-9]*)/sales$~D', (string) $path, $reportMatches) === 1;

        $allowedMethods = match (true) {
            $salesRoute || $path === '/reports/sales/summary' => ['GET'],
            $statusRoute => ['PATCH'],
            $eventRoute && !isset($matches[2]) => ['GET', 'PUT'],
            $path === '/purchases' || $path === '/events' => ['GET', 'POST'],
            $path === '/auth/me' || $eventRoute => ['GET'],
            $path === '/auth/register' || $path === '/auth/login' => ['POST'],
            default => [],
        };

        if ($allowedMethods === []) {
            Json::respond(404, ['error' => 'Ruta no encontrada.']);
        }
        if (!in_array($method, $allowedMethods, true)) {
            header('Allow: ' . implode(', ', $allowedMethods));
            Json::respond(405, ['error' => 'Método HTTP no permitido.']);
        }

        try {
            if ($salesRoute || $path === '/reports/sales/summary') {
                $id = $salesRoute ? $this->eventId($reportMatches[1]) : null;
                $this->auth->requireAdmin($authorization);
                $report = $this->reports->get($id);
                header('Cache-Control: no-store');
                Json::respond(200, $report);
            }

            if ($eventRoute) {
                $this->dispatchEvent($this->eventId($matches[1]), $method, $matches[2] ?? '', $authorization);
            }

            match ($path) {
                '/events' => $method === 'GET'
                    ? Json::respond(200, ['items' => $this->events->list()])
                    : $this->createEvent($authorization),
                '/purchases' => $this->dispatchPurchase($method, $authorization),
                '/auth/register' => Json::respond(201, $this->auth->register(Json::body())),
                '/auth/login' => $this->login(),
                '/auth/me' => $this->currentUser($authorization),
            };
        } catch (ApplicationError $error) {
            $status = match ($error->kind) {
                ErrorKind::Unauthenticated => 401,
                ErrorKind::Forbidden => 403,
                ErrorKind::NotFound => 404,
                ErrorKind::Conflict => 409,
                ErrorKind::Validation => 422,
            };
            if ($status === 401 && $path !== '/auth/login') {
                header('WWW-Authenticate: Bearer');
            }
            Json::respond($status, ['error' => $error->getMessage()]);
        } catch (Throwable $exception) {
            error_log('Falló la solicitud a la API: ' . get_class($exception) . ' ' . $exception->getCode());
            Json::respond(500, ['error' => 'Error interno del servidor.']);
        }
    }

    private function eventId(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new ApplicationError(ErrorKind::NotFound, 'Evento no encontrado.');
        }
        return $id;
    }

    private function dispatchEvent(int $id, string $method, string $suffix, string $authorization): never
    {
        if ($method === 'PATCH' || $method === 'PUT') {
            $this->auth->requireAdmin($authorization);
            $input = Json::body();
            $event = $method === 'PATCH' ? $this->events->changeStatus($id, $input) : $this->events->update($id, $input);
            Json::respond(200, $event);
        }

        $event = $this->events->get($id);
        Json::respond(200, $suffix === '/availability'
            ? ['event_id' => $event['id'], 'available_tickets' => $event['available_tickets']]
            : $event);
    }

    private function createEvent(string $authorization): never
    {
        $this->auth->requireAdmin($authorization);
        Json::respond(201, $this->events->create(Json::body()));
    }

    private function dispatchPurchase(string $method, string $authorization): never
    {
        if ($method === 'GET') {
            header('Cache-Control: no-store');
            $user = $this->auth->authenticate($authorization);
            Json::respond(200, ['items' => $this->purchases->history((int) $user['id'])]);
        }

        $user = $this->auth->authenticate($authorization);
        Json::respond(201, $this->purchases->purchase((int) $user['id'], Json::body()));
    }

    private function login(): never
    {
        $session = $this->auth->login(Json::body());
        header('Cache-Control: no-store');
        Json::respond(200, $session);
    }

    private function currentUser(string $authorization): never
    {
        header('Cache-Control: no-store');
        $user = $this->auth->authenticate($authorization);
        unset($user['role']);
        Json::respond(200, $user);
    }
}
