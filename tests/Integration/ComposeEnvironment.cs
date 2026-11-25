using System.Diagnostics;

namespace Ticketing.IntegrationTests;

public sealed class ComposeEnvironment : IAsyncLifetime
{
    private readonly string root = FindRepositoryRoot();
    private readonly string project = $"ticketing-test-{Guid.NewGuid():N}";
    private readonly string password = Guid.NewGuid().ToString("N");
    private bool cleanupRequired;

    public HttpClient Ticketing { get; } = new() { Timeout = TimeSpan.FromSeconds(10) };
    public HttpClient Reporting { get; } = new() { Timeout = TimeSpan.FromSeconds(10) };

    public async Task InitializeAsync()
    {
        await ComposeAsync(["config", "--quiet"]);
        await ComposeAsync(["ps", "--quiet"]);
        cleanupRequired = true;
        await ComposeAsync(["up", "--build", "--detach", "--wait", "--wait-timeout", "90"]);

        var migrations = Directory.GetFiles(Path.Combine(root, "db", "migrations"), "*.sql");
        foreach (var migration in migrations.Order(StringComparer.Ordinal))
        {
            await ComposeAsync(
                ["exec", "-T", "db", "psql", "-X", "-v", "ON_ERROR_STOP=1",
                    "-U", "ticketing", "-d", "ticketing"],
                await File.ReadAllBytesAsync(migration));
        }

        Ticketing.BaseAddress = await GetAddressAsync("api", "80");
        Reporting.BaseAddress = await GetAddressAsync("reports", "8080");
        await WaitUntilReadyAsync(Ticketing, "/health.php");
        await WaitUntilReadyAsync(Reporting, "/reports/events");
    }

    public async Task PromoteAdminAsync()
    {
        var result = await ComposeAsync(
            ["exec", "-T", "db", "psql", "-X", "-v", "ON_ERROR_STOP=1",
                "-U", "ticketing", "-d", "ticketing", "-c",
                "UPDATE users SET role = 'admin' WHERE email = 'admin@example.test'"]);
        Assert.Contains("UPDATE 1", result);
    }

    public async Task DisposeAsync()
    {
        Ticketing.Dispose();
        Reporting.Dispose();
        if (cleanupRequired)
        {
            await ComposeAsync(["down", "--volumes", "--remove-orphans"]);
        }
    }

    private async Task<Uri> GetAddressAsync(string service, string port)
        => new("http://" + (await ComposeAsync(["port", service, port])).Trim());

    private static async Task WaitUntilReadyAsync(HttpClient client, string path)
    {
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(30));
        try
        {
            while (true)
            {
                try
                {
                    using var response = await client.GetAsync(path, deadline.Token);
                    if (response.IsSuccessStatusCode)
                    {
                        return;
                    }
                }
                catch (HttpRequestException)
                {
                    // El contenedor puede estar arrancando.
                }
                catch (OperationCanceledException) when (!deadline.IsCancellationRequested)
                {
                    // La solicitud individual agotó su tiempo de espera.
                }

                await Task.Delay(500, deadline.Token);
            }
        }
        catch (OperationCanceledException ex) when (deadline.IsCancellationRequested)
        {
            throw new TimeoutException($"El servicio no está listo en {client.BaseAddress}{path.TrimStart('/')}.", ex);
        }
    }

    private async Task<string> ComposeAsync(string[] args, byte[]? input = null)
    {
        var start = new ProcessStartInfo("docker")
        {
            WorkingDirectory = root,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            RedirectStandardInput = input is not null
        };
        start.Environment["POSTGRES_PASSWORD"] = password;

        string[] options = ["compose", "--project-name", project, "-f", "compose.yaml", "-f", "tests/compose.test.yaml"];
        foreach (var argument in options.Concat(args))
        {
            start.ArgumentList.Add(argument);
        }

        using var process = Process.Start(start) ?? throw new InvalidOperationException("No se pudo iniciar Docker.");
        using var deadline = new CancellationTokenSource(TimeSpan.FromMinutes(10));
        var output = process.StandardOutput.ReadToEndAsync();
        var errors = process.StandardError.ReadToEndAsync();

        try
        {
            if (input is not null)
            {
                await process.StandardInput.BaseStream.WriteAsync(input, deadline.Token);
                process.StandardInput.Close();
            }

            await process.WaitForExitAsync(deadline.Token);
        }
        catch (OperationCanceledException ex) when (deadline.IsCancellationRequested)
        {
            if (!process.HasExited)
            {
                process.Kill();
            }
            throw new TimeoutException($"Docker Compose agotó el tiempo de espera en '{args[0]}'.", ex);
        }

        var stdout = await output;
        var stderr = await errors;
        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException($"Docker Compose falló en '{args[0]}':\n{stderr}\n{stdout}");
        }

        return stdout;
    }

    private static string FindRepositoryRoot()
    {
        for (DirectoryInfo? directory = new(AppContext.BaseDirectory); directory is not null; directory = directory.Parent)
        {
            if (File.Exists(Path.Combine(directory.FullName, "compose.yaml")))
            {
                return directory.FullName;
            }
        }

        throw new DirectoryNotFoundException("No se encontró la raíz del proyecto.");
    }
}
