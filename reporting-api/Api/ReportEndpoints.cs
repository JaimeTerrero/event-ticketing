using System.Net.Http.Headers;
using Ticketing.Reports.Application;

namespace Ticketing.Reports.Api;

public static class ReportEndpoints
{
    public static void MapReportEndpoints(this WebApplication app)
    {
        app.MapGet("/reports/events", GetEventsAsync);
        app.MapGet("/reports/sales/summary",
            (HttpContext context, IReportsGateway gateway, ILoggerFactory loggers)
                => GetSalesAsync(null, context, gateway, loggers));
        app.MapGet("/reports/events/{eventId:long:min(1)}/sales",
            (long eventId, HttpContext context, IReportsGateway gateway, ILoggerFactory loggers)
                => GetSalesAsync(eventId, context, gateway, loggers));
    }

    private static async Task<IResult> GetEventsAsync(
        IReportsGateway gateway, ILoggerFactory loggers, CancellationToken cancellationToken)
    {
        try
        {
            return Results.Ok(await gateway.GetEventsAsync(cancellationToken));
        }
        catch (ReportsGatewayException ex) when (ex.Failure == ReportsFailure.Timeout)
        {
            return Timeout();
        }
        catch (ReportsGatewayException ex)
        {
            loggers.CreateLogger("Reports").LogWarning(ex, "Falló la consulta de eventos al servicio PHP.");
            return Error(502, "No se pudieron consultar los eventos del servicio principal.");
        }
    }

    private static async Task<IResult> GetSalesAsync(
        long? eventId, HttpContext context, IReportsGateway gateway, ILoggerFactory loggers)
    {
        context.Response.Headers.CacheControl = "no-store";

        if (!AuthenticationHeaderValue.TryParse(
                context.Request.Headers.Authorization.ToString(), out var authorization) ||
            !authorization.Scheme.Equals("Bearer", StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(authorization.Parameter))
        {
            return Unauthorized(context);
        }

        try
        {
            return Results.Ok(await gateway.GetSalesAsync(eventId, authorization.Parameter, context.RequestAborted));
        }
        catch (ReportsGatewayException ex)
        {
            switch (ex.Failure)
            {
                case ReportsFailure.Unauthorized:
                    return Unauthorized(context);
                case ReportsFailure.Forbidden:
                    return Error(403, "Se requieren permisos de administrador.");
                case ReportsFailure.NotFound:
                    return Error(404, "Evento no encontrado.");
                case ReportsFailure.Timeout:
                    return Timeout();
                default:
                    loggers.CreateLogger("Reports").LogWarning(ex, "Falló la consulta de ventas al servicio PHP.");
                    return Error(502, "No se pudo consultar el reporte de ventas.");
            }
        }
    }

    private static IResult Unauthorized(HttpContext context)
    {
        context.Response.Headers.WWWAuthenticate = "Bearer";
        return Error(401, "Se requiere autenticación.");
    }

    private static IResult Timeout()
        => Error(504, "El servicio principal tardó demasiado en responder.");

    private static IResult Error(int status, string message)
        => Results.Json(new { error = message }, statusCode: status);
}
