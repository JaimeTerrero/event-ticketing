using Ticketing.Reports.Api;
using Ticketing.Reports.Application;
using Ticketing.Reports.Infrastructure;

var builder = WebApplication.CreateBuilder(args);
var phpApiUrl = builder.Configuration["PhpApi:BaseUrl"]
    ?? throw new InvalidOperationException("Configura PhpApi__BaseUrl.");

builder.Services.AddHttpClient<IReportsGateway, TicketingClient>(client =>
{
    client.BaseAddress = new Uri(phpApiUrl);
    client.Timeout = TimeSpan.FromSeconds(5);
});

var app = builder.Build();
app.MapReportEndpoints();
app.Run();
