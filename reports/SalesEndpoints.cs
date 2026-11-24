using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;

public static class SalesEndpoints
{
    public static void MapSalesEndpoints(this WebApplication app)
    {
        app.MapGet("/reports/sales/summary",
            (HttpContext context, PhpTicketingClient client, ILogger<PhpTicketingClient> logger)
                => GetSalesAsync(null, context, client, logger));

        app.MapGet("/reports/events/{eventId:long:min(1)}/sales",
            (long eventId, HttpContext context, PhpTicketingClient client, ILogger<PhpTicketingClient> logger)
                => GetSalesAsync(eventId, context, client, logger));
    }

    private static async Task<IResult> GetSalesAsync(
        long? eventId,
        HttpContext context,
        PhpTicketingClient client,
        ILogger<PhpTicketingClient> logger)
    {
        context.Response.Headers.CacheControl = "no-store";

        if (!AuthenticationHeaderValue.TryParse(
                context.Request.Headers.Authorization.ToString(), out var authorization) ||
            !authorization.Scheme.Equals("Bearer", StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(authorization.Parameter))
        {
            return Unauthorized(context);
        }

        var cancellationToken = context.RequestAborted;

        try
        {
            return Results.Ok(await client.GetSalesAsync(eventId, authorization, cancellationToken));
        }
        catch (HttpRequestException ex) when (ex.StatusCode == HttpStatusCode.Unauthorized)
        {
            return Unauthorized(context);
        }
        catch (HttpRequestException ex) when (ex.StatusCode == HttpStatusCode.Forbidden)
        {
            return Error(403, "Se requieren permisos de administrador.");
        }
        catch (HttpRequestException ex) when (ex.StatusCode == HttpStatusCode.NotFound)
        {
            return Error(404, "Evento no encontrado.");
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            return Error(504, "El servicio principal tardó demasiado en responder.");
        }
        catch (Exception ex) when (ex is HttpRequestException or JsonException)
        {
            logger.LogWarning(ex, "Falló la consulta de ventas al servicio PHP.");
            return Error(502, "No se pudo consultar el reporte de ventas.");
        }
    }

    private static IResult Unauthorized(HttpContext context)
    {
        context.Response.Headers.WWWAuthenticate = "Bearer";
        return Error(401, "Se requiere autenticación.");
    }

    private static IResult Error(int status, string message)
        => Results.Json(new { error = message }, statusCode: status);
}
