using System.Net.Http.Headers;
using System.Text.Json;

public sealed class PhpTicketingClient(HttpClient httpClient)
{
    public Task<JsonElement> GetEventsAsync(CancellationToken cancellationToken)
        => httpClient.GetFromJsonAsync<JsonElement>("events", cancellationToken);

    public async Task<JsonElement> GetSalesAsync(
        long? eventId,
        AuthenticationHeaderValue authorization,
        CancellationToken cancellationToken)
    {
        var path = eventId.HasValue
            ? $"reports/events/{eventId.Value}/sales"
            : "reports/sales/summary";

        using var request = new HttpRequestMessage(HttpMethod.Get, path);
        request.Headers.Authorization = authorization;

        using var response = await httpClient.SendAsync(request, cancellationToken);
        response.EnsureSuccessStatusCode();

        return await response.Content.ReadFromJsonAsync<JsonElement>(
            cancellationToken: cancellationToken);
    }
}
