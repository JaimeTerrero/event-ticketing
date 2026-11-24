<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function connectDatabase(): PDO
{
    return new PDO(
        'pgsql:host=db;port=5432;dbname=ticketing',
        'ticketing',
        getenv('POSTGRES_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function registerUser(): never
{
    try {
        $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'JSON no válido.']);
    }

    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
    }

    $name = is_string($body->name ?? null) ? trim($body->name) : '';
    $email = is_string($body->email ?? null) ? strtolower(trim($body->email)) : '';
    $password = $body->password ?? null;

    if ($name === '' || strlen($name) > 120 ||
        strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password) || strlen($password) < 12 ||
        strlen($password) > 72 || str_contains($password, "\0")) {
        respond(422, ['error' => 'Ingresa un nombre y un correo válidos y una contraseña de 12 a 72 bytes.']);
    }

    $statement = connectDatabase()->prepare(
        'INSERT INTO users (name, email, password_hash)
         VALUES (:name, :email, :password_hash)
         ON CONFLICT (email) DO NOTHING
         RETURNING id, name, email, created_at'
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);

    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        respond(409, ['error' => 'El correo electrónico ya está registrado.']);
    }

    respond(201, $user);
}

function loginUser(): never
{
    try {
        $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'JSON no válido.']);
    }

    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
    }

    $email = is_string($body->email ?? null) ? strtolower(trim($body->email)) : '';
    $password = $body->password ?? null;

    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password) || strlen($password) < 12 ||
        strlen($password) > 72 || str_contains($password, "\0")) {
        respond(401, ['error' => 'Correo electrónico o contraseña incorrectos.']);
    }

    $db = connectDatabase();
    $statement = $db->prepare('SELECT id, password_hash FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    // Use a dummy hash to perform the password check even for an unknown email.
    $hash = $user['password_hash'] ??
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    if (!password_verify($password, $hash) || $user === false) {
        respond(401, ['error' => 'Correo electrónico o contraseña incorrectos.']);
    }

    $token = bin2hex(random_bytes(32));
    $session = $db->prepare(
        "INSERT INTO user_sessions (token_hash, user_id, expires_at)
         VALUES (:token_hash, :user_id, now() + interval '24 hours')"
    );
    $session->execute([
        'token_hash' => hash('sha256', $token),
        'user_id' => $user['id'],
    ]);

    header('Cache-Control: no-store');
    respond(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 86400]);
}

function requireUser(bool $includeRole = false): array
{
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $authorization = $headers['authorization'] ?? '';

    if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $authorization, $matches)) {
        unauthorized();
    }

    $statement = connectDatabase()->prepare(
        'SELECT u.id, u.name, u.email, u.created_at, u.role
         FROM user_sessions AS s
         JOIN users AS u ON u.id = s.user_id
         WHERE s.token_hash = :token_hash AND s.expires_at > now()'
    );
    $statement->execute(['token_hash' => hash('sha256', $matches[1])]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if ($user === false) {
        unauthorized();
    }

    if (!$includeRole) {
        unset($user['role']);
    }
    return $user;
}

function unauthorized(): never
{
    header('WWW-Authenticate: Bearer');
    respond(401, ['error' => 'Se requiere autenticación.']);
}

function readEventInput(): array
{
    try {
        $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'JSON no válido.']);
    }

    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
    }

    $name = is_string($body->name ?? null) ? trim($body->name) : '';
    $description = is_string($body->description ?? null) ? trim($body->description) : '';
    $total = $body->total_tickets ?? null;
    $price = $body->ticket_price ?? null;
    $date = $body->event_date ?? null;

    if ($name === '' || strlen($name) > 200 ||
        $description === '' || strlen($description) > 5000 ||
        !is_int($total) || $total < 1 || $total > 2147483647 ||
        !is_string($price) || !preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/D', $price) ||
        $price === '0.00') {
        respond(422, ['error' => 'Ingresa un nombre, una descripción, una cantidad de entradas mayor que cero y un precio positivo con dos decimales.']);
    }

    $eventDate = is_string($date)
        ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $date)
        : false;

    if ($eventDate === false || $eventDate->format('Y-m-d\TH:i:sP') !== $date ||
        $eventDate <= new DateTimeImmutable()) {
        respond(422, ['error' => 'Ingresa una fecha futura en event_date con un desfase horario numérico respecto de UTC.']);
    }

    return [$name, $description, $date, $total, $price];
}

function createEvent(): never
{
    $user = requireUser(true);
    if ($user['role'] !== 'admin') {
        respond(403, ['error' => 'Se requieren permisos de administrador.']);
    }

    [$name, $description, $date, $total, $price] = readEventInput();

    $statement = connectDatabase()->prepare(
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

    respond(201, $statement->fetch(PDO::FETCH_ASSOC));
}

function listEvents(): never
{
    $events = connectDatabase()->query(
        "SELECT id, name, description, event_date, total_tickets,
                available_tickets, ticket_price, status
         FROM events
         WHERE status = 'published'
         ORDER BY event_date, id"
    );
    respond(200, ['items' => $events->fetchAll(PDO::FETCH_ASSOC)]);
}

function getEvent(int $id, bool $availabilityOnly): never
{
    $statement = connectDatabase()->prepare(
        "SELECT id, name, description, event_date, total_tickets,
                available_tickets, ticket_price, status
         FROM events
         WHERE id = :id AND status = 'published'"
    );
    $statement->execute(['id' => $id]);
    $event = $statement->fetch(PDO::FETCH_ASSOC);

    if ($event === false) {
        respond(404, ['error' => 'Evento no encontrado.']);
    }

    if ($availabilityOnly) {
        respond(200, [
            'event_id' => $event['id'],
            'available_tickets' => $event['available_tickets'],
        ]);
    }

    respond(200, $event);
}

function changeEventStatus(int $id): never
{
    $user = requireUser(true);
    if ($user['role'] !== 'admin') {
        respond(403, ['error' => 'Se requieren permisos de administrador.']);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'JSON no válido.']);
    }

    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
    }

    $status = $body->status ?? null;
    if (!in_array($status, ['published', 'cancelled'], true)) {
        respond(422, ['error' => 'El estado debe ser published o cancelled.']);
    }

    $db = connectDatabase();
    $statement = $db->prepare(
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
    $event = $statement->fetch(PDO::FETCH_ASSOC);

    if ($event !== false) {
        respond(200, $event);
    }

    $exists = $db->prepare('SELECT 1 FROM events WHERE id = :id');
    $exists->execute(['id' => $id]);
    if ($exists->fetchColumn() === false) {
        respond(404, ['error' => 'Evento no encontrado.']);
    }

    respond(409, ['error' => 'No se permite este cambio de estado.']);
}

function purchaseTickets(): never
{
    $user = requireUser();

    try {
        $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'JSON no válido.']);
    }

    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
    }

    $eventId = $body->event_id ?? null;
    $quantity = $body->quantity ?? null;

    if (!is_int($eventId) || $eventId < 1 ||
        !is_int($quantity) || $quantity < 1 || $quantity > 2147483647) {
        respond(422, ['error' => 'event_id y quantity deben ser enteros positivos.']);
    }

    $statement = connectDatabase()->prepare(
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
        'user_id' => $user['id'],
    ]);
    $sale = $statement->fetch(PDO::FETCH_ASSOC);

    if ($sale === false) {
        respond(409, ['error' => 'El evento o la cantidad de entradas solicitada no están disponibles.']);
    }

    respond(201, $sale);
}

function listPurchases(): never
{
    header('Cache-Control: no-store');
    $user = requireUser();

    $statement = connectDatabase()->prepare(
        'SELECT s.id, s.event_id, e.name AS event_name, s.quantity,
                s.total_amount, s.purchase_date, s.status
         FROM ticket_sales AS s
         JOIN events AS e ON e.id = s.event_id
         WHERE s.user_id = :user_id
         ORDER BY s.purchase_date DESC, s.id DESC'
    );
    $statement->execute(['user_id' => $user['id']]);

    respond(200, ['items' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
}

function updateEvent(int $id): never
{
    $user = requireUser(true);
    if ($user['role'] !== 'admin') {
        respond(403, ['error' => 'Se requieren permisos de administrador.']);
    }

    [$name, $description, $date, $total, $price] = readEventInput();

    $db = connectDatabase();
    $statement = $db->prepare(
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
    $event = $statement->fetch(PDO::FETCH_ASSOC);

    if ($event !== false) {
        respond(200, $event);
    }

    $exists = $db->prepare('SELECT 1 FROM events WHERE id = :id');
    $exists->execute(['id' => $id]);
    if ($exists->fetchColumn() === false) {
        respond(404, ['error' => 'Evento no encontrado.']);
    }

    respond(409, ['error' => 'El evento está cancelado o la capacidad es menor que la cantidad de entradas vendidas.']);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$eventRoute = preg_match('~^/events/([1-9][0-9]*)(/availability|/status)?$~D', $path, $matches) === 1;

$statusRoute = $eventRoute && ($matches[2] ?? '') === '/status';

$allowedMethods = match (true) {
    $statusRoute => ['PATCH'],
    $eventRoute && !isset($matches[2]) => ['GET', 'PUT'],
    $path === '/purchases' => ['GET', 'POST'],
    $path === '/events' => ['GET', 'POST'],
    $path === '/auth/me' || $eventRoute => ['GET'],
    in_array($path, ['/auth/register', '/auth/login'], true) => ['POST'],
    default => [],
};

if ($allowedMethods === []) {
    respond(404, ['error' => 'Ruta no encontrada.']);
}
if (!in_array($method, $allowedMethods, true)) {
    header('Allow: ' . implode(', ', $allowedMethods));
    respond(405, ['error' => 'Método HTTP no permitido.']);
}

try {
    if ($eventRoute) {
        $id = filter_var($matches[1], FILTER_VALIDATE_INT);
        if ($id === false) {
            respond(404, ['error' => 'Evento no encontrado.']);
        }
        if ($statusRoute) {
            changeEventStatus($id);
        }
        if ($method === 'PUT') {
            updateEvent($id);
        }
        getEvent($id, isset($matches[2]));
    }

    match ($path) {
        '/events' => $method === 'GET' ? listEvents() : createEvent(),
        '/auth/me' => showCurrentUser(),
        '/purchases' => $method === 'GET' ? listPurchases() : purchaseTickets(),
        '/auth/login' => loginUser(),
        '/auth/register' => registerUser(),
    };
} catch (Throwable $exception) {
    error_log('Falló la solicitud a la API: ' . get_class($exception) . ' ' . $exception->getCode());
    respond(500, ['error' => 'Error interno del servidor.']);
}

function showCurrentUser(): never
{
    header('Cache-Control: no-store');
    respond(200, requireUser());
}





