using System.Text.Json;

namespace Ticketing.Reports.Application;

public interface IReportsGateway
{
    Task<JsonElement> GetEventsAsync(CancellationToken cancellationToken);
    Task<JsonElement> GetSalesAsync(long? eventId, string token, CancellationToken cancellationToken);
}
