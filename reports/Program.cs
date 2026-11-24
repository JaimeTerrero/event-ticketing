var builder = WebApplication.CreateBuilder(args);

var phpApiUrl = builder.Configuration["PhpApi:BaseUrl"]
    ?? throw new InvalidOperationException("Configura PhpApi__BaseUrl.");

builder.Services.AddHttpClient<PhpTicketingClient>(client =>
{
    client.BaseAddress = new Uri(phpApiUrl);
    client.Timeout = TimeSpan.FromSeconds(5);
});

var app = builder.Build();

app.MapGet("/reports/events", async (
    PhpTicketingClient ticketing,
    CancellationToken cancellationToken) =>
{
    try
    {
        var events = await ticketing.GetEventsAsync(cancellationToken);
        return Results.Ok(events);
    }
    catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
    {
        return Results.Json(
            new { error = "El servicio principal tardó demasiado en responder." },
            statusCode: 504);
    }
    catch (Exception ex) when (ex is HttpRequestException or System.Text.Json.JsonException)
    {
        app.Logger.LogWarning(ex, "Falló la consulta de eventos al servicio PHP.");
        return Results.Json(
            new { error = "No se pudieron consultar los eventos del servicio principal." },
            statusCode: 502);
    }
});

app.MapSalesEndpoints();

app.Run();

