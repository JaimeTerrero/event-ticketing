using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace Ticketing.IntegrationTests;

public sealed class PurchaseReportsTests(ComposeEnvironment environment) : IClassFixture<ComposeEnvironment>
{
    private const string Password = "PruebaLocal-1234!";

    [Fact]
    public async Task PurchaseUpdatesInventoryAndReports()
    {
        const string adminEmail = "admin@example.test";
        const string customerEmail = "customer@example.test";
        await RegisterAsync(adminEmail);
        await RegisterAsync(customerEmail);
        await environment.PromoteAdminAsync();
        var admin = await LoginAsync(adminEmail);
        var customer = await LoginAsync(customerEmail);

        const string eventName = "Concierto de prueba";
        var ticketing = environment.Ticketing;
        var reporting = environment.Reporting;
        var createdEvent = await RequestAsync(ticketing, HttpMethod.Post, "/events", HttpStatusCode.Created, admin,
            new
            {
                name = eventName,
                description = "Evento para validar compras e informes",
                event_date = DateTimeOffset.UtcNow.AddDays(7).ToString("yyyy-MM-dd'T'HH:mm:sszzz"),
                total_tickets = 3,
                ticket_price = "1500.00"
            });
        var eventId = createdEvent.GetProperty("id").GetInt64();
        await RequestAsync(ticketing, HttpMethod.Patch, $"/events/{eventId}/status", HttpStatusCode.OK, admin,
            new { status = "published" });
        await AssertAvailabilityAsync(eventId, 3);

        var purchase = new { event_id = eventId, quantity = 2 };
        await RequestAsync(ticketing, HttpMethod.Post, "/purchases", HttpStatusCode.Unauthorized, body: purchase);
        var sale = await RequestAsync(ticketing, HttpMethod.Post, "/purchases", HttpStatusCode.Created, customer, purchase);
        Assert.Equal(2, sale.GetProperty("quantity").GetInt32());
        Assert.Equal("3000.00", sale.GetProperty("total_amount").GetString());
        Assert.Equal("confirmed", sale.GetProperty("status").GetString());
        await AssertAvailabilityAsync(eventId, 1);

        await RequestAsync(ticketing, HttpMethod.Post, "/purchases", HttpStatusCode.Conflict, customer, purchase);
        await AssertAvailabilityAsync(eventId, 1);
        var history = await RequestAsync(ticketing, HttpMethod.Get, "/purchases", HttpStatusCode.OK, customer);
        var purchaseRecord = Assert.Single(history.GetProperty("items").EnumerateArray());
        Assert.Equal(sale.GetProperty("id").GetInt64(), purchaseRecord.GetProperty("id").GetInt64());

        var catalog = await RequestAsync(reporting, HttpMethod.Get, "/reports/events", HttpStatusCode.OK);
        var publishedEvent = Assert.Single(catalog.GetProperty("items").EnumerateArray());
        Assert.Equal(eventId, publishedEvent.GetProperty("id").GetInt64());
        Assert.Equal(1, publishedEvent.GetProperty("available_tickets").GetInt32());

        const string summaryPath = "/reports/sales/summary";
        await RequestAsync(reporting, HttpMethod.Get, summaryPath, HttpStatusCode.Unauthorized);
        await RequestAsync(reporting, HttpMethod.Get, summaryPath, HttpStatusCode.Forbidden, customer);
        var summary = await RequestAsync(reporting, HttpMethod.Get, summaryPath, HttpStatusCode.OK, admin);
        AssertSalesTotals(summary);

        var eventSales = await RequestAsync(reporting, HttpMethod.Get, $"/reports/events/{eventId}/sales",
            HttpStatusCode.OK, admin);
        AssertSalesTotals(eventSales);
        Assert.Equal(eventId, eventSales.GetProperty("event_id").GetInt64());
        Assert.Equal(eventName, eventSales.GetProperty("event_name").GetString());
    }

    private Task<JsonElement> RegisterAsync(string email)
        => RequestAsync(environment.Ticketing, HttpMethod.Post, "/auth/register", HttpStatusCode.Created,
            body: new { name = "Usuario de prueba", email, password = Password });

    private async Task<string> LoginAsync(string email)
    {
        var session = await RequestAsync(environment.Ticketing, HttpMethod.Post, "/auth/login", HttpStatusCode.OK,
            body: new { email, password = Password });
        return session.GetProperty("access_token").GetString()
            ?? throw new InvalidOperationException("La respuesta no incluye un token.");
    }

    private async Task AssertAvailabilityAsync(long eventId, int count)
    {
        var eventAvailability = await RequestAsync(environment.Ticketing, HttpMethod.Get,
            $"/events/{eventId}/availability", HttpStatusCode.OK);
        Assert.Equal(count, eventAvailability.GetProperty("available_tickets").GetInt32());
    }

    private static void AssertSalesTotals(JsonElement report)
    {
        Assert.Equal(1, report.GetProperty("purchase_count").GetInt32());
        Assert.Equal(2, report.GetProperty("tickets_sold").GetInt32());
        Assert.Equal("3000.00", report.GetProperty("total_revenue").GetString());
    }

    private static async Task<JsonElement> RequestAsync(HttpClient client, HttpMethod method,
        string path, HttpStatusCode expected, string? token = null, object? body = null)
    {
        using var request = new HttpRequestMessage(method, path);
        if (token is not null)
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }
        if (body is not null)
        {
            request.Content = JsonContent.Create(body);
        }

        using var response = await client.SendAsync(request);
        var payload = await response.Content.ReadAsStringAsync();
        Assert.True(response.StatusCode == expected,
            $"{method} {path}: se esperaba {(int)expected}, se recibió {(int)response.StatusCode}. {payload}");
        Assert.Equal("application/json", response.Content.Headers.ContentType?.MediaType);
        using var json = JsonDocument.Parse(payload);
        return json.RootElement.Clone();
    }
}
