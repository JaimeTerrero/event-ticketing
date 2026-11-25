# Event Ticketing

Sistema backend para publicar eventos, vender entradas y consultar ventas. La API de reportes obtiene sus datos por HTTP de la API de venta de entradas.

[Arranque local](#arranque-local) · [Demostración](#demostración) · [OpenAPI](#openapi) · [Pruebas automatizadas](#pruebas-automatizadas)

| Componente | Qué hace | Dirección local |
| --- | --- | --- |
| [Venta de entradas](ticketing-api/) | PHP 8.4. Gestiona cuentas, eventos y compras. Es el único servicio que accede a PostgreSQL. | [Puerto 8080](http://127.0.0.1:8080/health.php) |
| [Reportes](reporting-api/) | ASP.NET Core 10. Consulta el catálogo y los reportes de ventas a través de la API de entradas. | [Puerto 8081](http://127.0.0.1:8081/reports/events) |
| [Migraciones](db/migrations/) | Tablas, relaciones e índices para PostgreSQL 17. | `127.0.0.1:5433` |

## Arranque local

Se necesita Git y Docker con el comando `docker compose`. Los comandos de esta guía están escritos para PowerShell. El SDK de .NET 10 solo hace falta en el equipo si se van a ejecutar las pruebas de xUnit.

```powershell
git clone https://github.com/JaimeTerrero/event-ticketing.git
Set-Location event-ticketing

if (-not (Test-Path .env)) {
    $password = [guid]::NewGuid().ToString('N')
    "POSTGRES_PASSWORD=$password" | Set-Content -Encoding ascii .env
}

docker compose up -d --build --wait
```

`POSTGRES_PASSWORD` se lee de `.env` y se usa para inicializar PostgreSQL y conectar la API de entradas. Conserva esa contraseña mientras uses el mismo volumen. Compose configura `PhpApi__BaseUrl=http://api/` para las llamadas entre contenedores.

En una base nueva, aplica las migraciones en orden una sola vez:

```powershell
Get-ChildItem .\db\migrations\*.sql | Sort-Object Name | ForEach-Object {
    Get-Content -Raw $_.FullName |
        docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U ticketing -d ticketing
    if ($LASTEXITCODE -ne 0) { throw "Falló la migración $($_.Name)" }
}

Invoke-RestMethod http://127.0.0.1:8080/health.php
Invoke-RestMethod http://127.0.0.1:8081/reports/events
```

La primera consulta devuelve `status: ok`. La segunda devuelve un catálogo con `items` vacío si todavía no hay eventos publicados. Para detener los contenedores y conservar los datos, usa `docker compose down`.

## OpenAPI

| Servicio | Contrato OpenAPI |
| --- | --- |
| Venta de entradas | [Abrir JSON en local](http://127.0.0.1:8080/openapi.json) · [Archivo en el repositorio](ticketing-api/public/openapi.json) |
| Reportes | [Abrir JSON en local](http://127.0.0.1:8081/openapi.json) · [Archivo en el repositorio](reporting-api/wwwroot/openapi.json) |

Puedes importar cualquiera de los archivos en Postman. Para una ruta protegida, usa el `access_token` de `POST /auth/login` como **Bearer Token**. Los reportes de ventas y las operaciones de administración de eventos requieren el rol `admin`.

## Demostración

La demostración parte de una base recién migrada. Publica un evento con tres entradas, compra dos y confirma que .NET refleja la venta.

### 1. Preparar las cuentas

```powershell
$api = 'http://127.0.0.1:8080'
$reportingApi = 'http://127.0.0.1:8081'
$json = 'application/json'
$demoPassword = 'Demostracion-2027!'

foreach ($email in 'admin@example.test', 'cliente@example.test') {
    $registration = @{
        name = $email.Split('@')[0]
        email = $email
        password = $demoPassword
    } | ConvertTo-Json
    Invoke-RestMethod "$api/auth/register" -Method Post -ContentType $json -Body $registration |
        Out-Null
}

docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U ticketing -d ticketing -c "UPDATE users SET role = 'admin' WHERE email = 'admin@example.test';"
if ($LASTEXITCODE -ne 0) { throw 'No se pudo asignar el rol de administrador.' }

function Get-DemoHeaders($email) {
    $credentials = @{ email = $email; password = $demoPassword } | ConvertTo-Json
    $session = Invoke-RestMethod "$api/auth/login" -Method Post -ContentType $json -Body $credentials
    @{ Authorization = "Bearer $($session.access_token)" }
}

$admin = Get-DemoHeaders 'admin@example.test'
$customer = Get-DemoHeaders 'cliente@example.test'
```

La actualización en PostgreSQL debe mostrar `UPDATE 1`. El administrador publicará el evento y consultará los reportes. El cliente hará la compra.

### 2. Publicar un evento

```powershell
$eventBody = @{
    name = 'Concierto de prueba'
    description = 'Evento para comprobar el flujo de compra'
    event_date = [DateTimeOffset]::UtcNow.AddDays(7).ToString("yyyy-MM-dd'T'HH:mm:sszzz")
    total_tickets = 3
    ticket_price = '1500.00'
} | ConvertTo-Json
$event = Invoke-RestMethod "$api/events" -Method Post -Headers $admin -ContentType $json -Body $eventBody
$id = $event.id

Invoke-RestMethod "$api/events/$id/status" -Method Patch -Headers $admin -ContentType $json -Body '{"status":"published"}'
```

La respuesta debe mostrar `status: published`.

### 3. Comprar entradas y comprobar el reporte

```powershell
$purchase = @{ event_id = $id; quantity = 2 } | ConvertTo-Json
Invoke-RestMethod "$api/purchases" -Method Post -Headers $customer -ContentType $json -Body $purchase
Invoke-RestMethod "$api/events/$id/availability"
Invoke-RestMethod "$reportingApi/reports/events/$id/sales" -Headers $admin
Invoke-RestMethod "$reportingApi/reports/sales/summary" -Headers $admin
```

| Consulta | Resultado esperado |
| --- | --- |
| Compra | `status: confirmed` y `total_amount: "3000.00"` |
| Disponibilidad | `available_tickets: 1` |
| Reportes en .NET | `purchase_count: 1`, `tickets_sold: 2` y `total_revenue: "3000.00"` |

Repetir la compra de dos entradas devuelve `409 Conflict`. El cliente puede consultar su historial con `GET /purchases` en el puerto 8080.

## Pruebas automatizadas

Con Docker activo, el SDK de .NET 10 instalado y una versión de Compose que admita `!override` en archivos de configuración, ejecuta:

```powershell
dotnet test .\tests\Integration\Ticketing.IntegrationTests.csproj
```

La prueba de xUnit levanta sus propios contenedores, usa puertos dinámicos, aplica las migraciones en un volumen independiente y lo elimina al terminar. Comprueba la compra, el inventario, el rechazo por falta de entradas, el historial, los permisos y los totales que devuelve .NET.

## Decisiones de implementación

- En PHP, el enrutador llama a los casos de uso y los repositorios se encargan del SQL. En .NET, los endpoints consultan a PHP mediante un cliente HTTP. La API de reportes reenvía el token y PHP valida el rol.
- La compra descuenta inventario e inserta la venta en una sola sentencia. La actualización solo procede para un evento publicado, con fecha futura y con entradas suficientes. El importe se calcula con el precio registrado en PostgreSQL.
- Las contraseñas se guardan con bcrypt. En la base se guarda el hash del token de sesión, que vence a las 24 horas. Los errores de aplicación se devuelven en JSON en español.
