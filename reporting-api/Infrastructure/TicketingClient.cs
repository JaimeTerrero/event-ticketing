using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using Ticketing.Reports.Application;

namespace Ticketing.Reports.Infrastructure;

public sealed class TicketingClient(HttpClient httpClient) : IReportsGateway
{
    public Task<JsonElement> GetEventsAsync(CancellationToken cancellationToken)
        => GetAsync("events", null, cancellationToken);

    public Task<JsonElement> GetSalesAsync(long? eventId, string token, CancellationToken cancellationToken)
    {
        var path = eventId is long id ? $"reports/events/{id}/sales" : "reports/sales/summary";
        return GetAsync(path, token, cancellationToken);
    }

    private async Task<JsonElement> GetAsync(string path, string? token, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, path);
        if (token is not null)
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }

        try
        {
            using var response = await httpClient.SendAsync(request, cancellationToken);
            response.EnsureSuccessStatusCode();
            return await response.Content.ReadFromJsonAsync<JsonElement>(cancellationToken: cancellationToken);
        }
        catch (OperationCanceledException ex) when (!cancellationToken.IsCancellationRequested)
        {
            throw new ReportsGatewayException(ReportsFailure.Timeout, ex);
        }
        catch (HttpRequestException ex)
        {
            var failure = ex.StatusCode switch
            {
                HttpStatusCode.Unauthorized => ReportsFailure.Unauthorized,
                HttpStatusCode.Forbidden => ReportsFailure.Forbidden,
                HttpStatusCode.NotFound => ReportsFailure.NotFound,
                _ => ReportsFailure.Unavailable
            };
            throw new ReportsGatewayException(failure, ex);
        }
        catch (JsonException ex)
        {
            throw new ReportsGatewayException(ReportsFailure.Unavailable, ex);
        }
    }
}
