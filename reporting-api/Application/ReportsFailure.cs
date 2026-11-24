namespace Ticketing.Reports.Application;

public enum ReportsFailure
{
    Unauthorized,
    Forbidden,
    NotFound,
    Timeout,
    Unavailable
}

public sealed class ReportsGatewayException(ReportsFailure failure, Exception innerException)
    : Exception("Falló la consulta al servicio principal.", innerException)
{
    public ReportsFailure Failure { get; } = failure;
}
