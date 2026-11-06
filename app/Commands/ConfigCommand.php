<?php

namespace App\Commands;

use App\Support\Docker;
use App\Support\DockerCompose;
use App\Support\EnvFile;
use App\Support\KamalProxy;
use App\Support\OverrideFile;
use LaravelZero\Framework\Commands\Command;

class ConfigCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'config
                            {--takeout : Also attach the app to the Takeout network}
                            {--host= : The hostname to serve the app on}
                            {--force : Overwrite an existing override file without asking}
                            {--route=* : Serve another service or port on its own hostname, as service:port:hostname}
                            {--update-env : With --route, point .env at the new hostnames without asking}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Configure a Sail app to run behind the proxy';

    /**
     * Execute the console command.
     */
    public function handle(
        Docker $docker,
        DockerCompose $compose,
        KamalProxy $proxy,
        OverrideFile $override,
    ): int {
        if (! $composeFile = $compose->composeFile()) {
            $this->error('No compose file found in the current directory.');

            return self::FAILURE;
        }

        $appService = $compose->appService();

        $result = $compose->services();

        if ($result->failed()) {
            $this->error("Could not read {$composeFile}.");
            $this->line(trim($result->errorOutput()) ?: trim($result->output()));

            return self::FAILURE;
        }

        $services = array_values(array_filter(array_map(trim(...), explode("\n", $result->output()))));

        if (! in_array($appService, $services, strict: true)) {
            $this->error("Service '{$appService}' not found in {$composeFile}.");
            $this->line('Set APP_SERVICE in .env to the correct service name.');

            return self::FAILURE;
        }

        $networks = $compose->networksFor($appService);
        $external = [];

        foreach ($this->proxyNetworks($docker) as $network) {
            if ($network === null) {
                return self::FAILURE;
            }

            $external[] = $network;

            if (! in_array($network, $networks, strict: true)) {
                $networks[] = $network;
            }
        }

        $path = $compose->path().'/'.config('proxy.override_file');

        if (is_file($path) && ! $this->option('force')) {
            if (! $this->confirm("'".config('proxy.override_file')."' already exists. Overwrite?")) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }
        }

        // The hostname becomes a network alias on the app service, so it has
        // to be settled before the file is written rather than at
        // registration time the way it used to be.
        $hostname = $this->hostname($compose, $appService);

        // Containers get a second name on a suffix libcurl will actually
        // resolve. See containerHostname().
        $containerHostname = $hostname === null ? null : $this->containerHostname($hostname);

        $aliases = $hostname === null ? [] : [
            config('proxy.network.name') => array_values(array_filter([$hostname, $containerHostname])),
        ];

        // These go into the override too, so they have to be settled by now
        // for the same reason the hostname does.
        $routes = $hostname === null ? [] : $this->routes($compose, $services, $appService, $hostname);

        if ($routes === null) {
            return self::FAILURE;
        }

        $routedServices = [];

        foreach ($routes as $route) {
            $names = $this->routeAliases($route['hostname']);

            if ($route['service'] === $appService) {
                $aliases[config('proxy.network.name')] = [...$aliases[config('proxy.network.name')], ...$names];

                continue;
            }

            $routedServices[$route['service']] ??= [
                'networks' => array_values(array_unique([
                    ...$compose->networksFor($route['service']),
                    config('proxy.network.name'),
                ])),
                'aliases' => [config('proxy.network.name') => []],
            ];

            array_push($routedServices[$route['service']]['aliases'][config('proxy.network.name')], ...$names);
        }

        $this->updateEnvFile(new EnvFile($compose->path().'/.env'), $routes, $appService);

        file_put_contents($path, $override->render($services, $appService, $networks, $external, $aliases, $routedServices));

        $this->info('Created '.config('proxy.override_file'));

        $compose->down();
        $compose->up();

        if ($hostname === null) {
            return self::SUCCESS;
        }

        if (! $proxy->isRunning()) {
            $this->newLine();
            $this->warn("Proxy is not running. Run 'sail-proxy install' first, then 'sail-proxy register'.");

            $this->reportPendingRoutes($compose, $routes, $appService);

            $this->reportContainerHostname($containerHostname);

            return self::SUCCESS;
        }

        $registered = $this->register($compose, $appService, $hostname);

        if ($registered !== self::SUCCESS) {
            return $registered;
        }

        $routed = $this->registerRoutes($compose, $routes, $appService);

        $this->reportContainerHostname($containerHostname);

        return $routed;
    }

    /**
     * The extra routes to serve, or null if any of those given are invalid.
     *
     * @param  array<int, string>  $services
     * @return array<int, array{service: string, port: string, hostname: string}>|null
     */
    protected function routes(DockerCompose $compose, array $services, string $appService, string $hostname): ?array
    {
        if ($this->option('route') !== []) {
            return $this->parseRoutes($services, $hostname);
        }

        // Giving --host is how a run says it is unattended, so it must not
        // start asking questions.
        return $this->option('host') ? [] : $this->askForRoutes($compose, $services, $appService, $hostname);
    }

    /**
     * Read the routes given as --route options.
     *
     * @param  array<int, string>  $services
     * @return array<int, array{service: string, port: string, hostname: string}>|null
     */
    protected function parseRoutes(array $services, string $hostname): ?array
    {
        $routes = [];

        foreach ($this->option('route') as $route) {
            $parts = explode(':', $route, 3);

            if (count($parts) !== 3 || ! ctype_digit($parts[1]) || $parts[2] === '') {
                $this->error("Invalid route '{$route}'. Expected service:port:hostname.");

                return null;
            }

            [$service, $port, $routeHostname] = $parts;

            if (! in_array($service, $services, strict: true)) {
                $this->error("Service '{$service}' not found in the compose file.");

                return null;
            }

            if ($this->hostnameTaken($routeHostname, $hostname, $routes)) {
                $this->error("Hostname '{$routeHostname}' is used more than once.");

                return null;
            }

            $routes[] = ['service' => $service, 'port' => $port, 'hostname' => $routeHostname];
        }

        return $routes;
    }

    /**
     * Ask for routes until the user has no more to add.
     *
     * @param  array<int, string>  $services
     * @return array<int, array{service: string, port: string, hostname: string}>
     */
    protected function askForRoutes(DockerCompose $compose, array $services, string $appService, string $hostname): array
    {
        $routes = [];

        $choices = [$appService, ...array_values(array_diff($services, [$appService]))];

        while ($this->confirm('Serve another service or port on its own hostname?', default: false)) {
            $service = $this->choice('Service', $choices, $appService);

            $ports = $this->availablePorts($compose, $service, $appService, $routes);

            // With several to pick from we'd only be guessing, and the wrong
            // guess is easy to make: mailpit declares SMTP before its dashboard.
            if (count($ports) > 1) {
                $this->line("  {$service} declares ports ".implode(', ', $ports).'. Choose the one that serves HTTP.');
            }

            $port = (string) $this->ask('Port', count($ports) === 1 ? $ports[0] : null);

            if (! ctype_digit($port)) {
                $this->error('The port has to be a number.');

                continue;
            }

            $default = ($service === $appService ? $port : $service).'.'.$hostname;

            while ($this->hostnameTaken($routeHostname = (string) $this->ask('Hostname', $default), $hostname, $routes)) {
                $this->error("Hostname '{$routeHostname}' is already in use.");
            }

            $routes[] = ['service' => $service, 'port' => $port, 'hostname' => $routeHostname];
        }

        return $routes;
    }

    /**
     * The ports the service declares that aren't being served already.
     *
     * @param  array<int, array{service: string, port: string, hostname: string}>  $routes
     * @return array<int, string>
     */
    protected function availablePorts(DockerCompose $compose, string $service, string $appService, array $routes): array
    {
        $taken = array_column(array_filter(
            $routes,
            fn (array $route): bool => $route['service'] === $service,
        ), 'port');

        if ($service === $appService) {
            $taken[] = '80';
        }

        return array_values(array_diff($compose->portsFor($service), $taken));
    }

    /**
     * Determine if a hostname is already spoken for.
     *
     * @param  array<int, array{service: string, port: string, hostname: string}>  $routes
     */
    protected function hostnameTaken(string $candidate, string $hostname, array $routes): bool
    {
        return in_array($candidate, [$hostname, ...array_column($routes, 'hostname')], strict: true);
    }

    /**
     * The network aliases for a hostname. See containerHostname().
     *
     * @return array<int, string>
     */
    protected function routeAliases(string $hostname): array
    {
        return array_values(array_filter([$hostname, $this->containerHostname($hostname)]));
    }

    /**
     * Point .env at the unique names of the services we are about to attach.
     *
     * Compose adds the service name as an alias on every network a service
     * joins, so once two projects expose a mailpit, "mailpit" on the shared
     * network resolves to both of them. The app is on that network as well, so
     * MAIL_HOST=mailpit can quietly reach the other project's container.
     *
     * @param  array<int, array{service: string, port: string, hostname: string}>  $routes
     */
    protected function updateEnvFile(EnvFile $env, array $routes, string $appService): void
    {
        $seen = [];

        foreach ($routes as $route) {
            // The app service joins the network either way, so a second route
            // to it adds no new name to collide on.
            if ($route['service'] === $appService || isset($seen[$route['service']])) {
                continue;
            }

            $seen[$route['service']] = true;

            $name = $this->containerHostname($route['hostname']) ?? $route['hostname'];

            $references = $env->references($route['service'], $name);

            if ($references === []) {
                $this->newLine();
                $this->line("  If your app calls {$route['service']} directly, use {$name} rather than {$route['service']}.");

                continue;
            }

            $this->newLine();
            $this->line("  .env still points at {$route['service']} by its short name, which other projects on the proxy can share:");
            $this->newLine();

            foreach ($references as $reference) {
                $this->line("    {$reference['from']}  ->  {$reference['to']}");
            }

            $this->newLine();

            if ($this->option('route') !== [] ? $this->option('update-env') : $this->confirm('Update .env?', default: true)) {
                $env->apply($references);

                $this->info('Updated .env');

                continue;
            }

            $this->warn("Left .env alone. {$route['service']} may resolve to another project's container until those lines change.");
        }
    }

    /**
     * Register the extra routes, carrying on past any that fail.
     *
     * @param  array<int, array{service: string, port: string, hostname: string}>  $routes
     */
    protected function registerRoutes(DockerCompose $compose, array $routes, string $appService): int
    {
        $status = self::SUCCESS;

        foreach ($routes as $route) {
            $container = $compose->containerFor($route['service']);

            if (! $container) {
                $this->error("Could not find running container for {$route['service']}.");

                $status = self::FAILURE;

                continue;
            }

            $registered = $this->call('register', [
                'container' => $container,
                'hostname' => $route['hostname'],
                'port' => $route['port'],
                ...$this->healthOptions($route, $appService),
            ]);

            if ($registered === self::SUCCESS) {
                continue;
            }

            $status = self::FAILURE;

            if ($route['service'] !== $appService) {
                $this->line("  If {$route['service']} doesn't return a 200 from /, retry with a path that does:");
                $this->line("    sail-proxy register {$container} {$route['hostname']} {$route['port']} --health-path=/some/path");
            }
        }

        return $status;
    }

    /**
     * The health check to register a route with.
     *
     * A route on the app service is usually something that isn't running yet,
     * like Vite, so it is checked by the app's own /up on the port that is. The
     * proxy routes to it from then on, answering 502 until it comes up. Any
     * other service has no /up, so all we can ask for is its root.
     *
     * @param  array{service: string, port: string, hostname: string}  $route
     * @return array<string, string>
     */
    protected function healthOptions(array $route, string $appService): array
    {
        return $route['service'] === $appService
            ? ['--health-port' => '80']
            : ['--health-path' => '/'];
    }

    /**
     * Tell the user how to register the routes once the proxy is up.
     *
     * @param  array<int, array{service: string, port: string, hostname: string}>  $routes
     */
    protected function reportPendingRoutes(DockerCompose $compose, array $routes, string $appService): void
    {
        foreach ($routes as $route) {
            $container = $compose->containerFor($route['service']) ?? '<container>';

            $options = [];

            foreach ($this->healthOptions($route, $appService) as $option => $value) {
                $options[] = "{$option}={$value}";
            }

            $this->line("  sail-proxy register {$container} {$route['hostname']} {$route['port']} ".implode(' ', $options));
        }
    }

    /**
     * The name other containers use to reach this app, if it needs one.
     *
     * libcurl resolves any ".localhost" name to 127.0.0.1 on its own, without
     * consulting a resolver, so the hostname we serve on is unreachable from
     * inside a container no matter what we put in DNS. A second alias on a
     * suffix libcurl leaves alone is the only way around it. A hostname that
     * does not end in ".localhost" already works and gets no second name.
     */
    protected function containerHostname(string $hostname): ?string
    {
        if (! str_ends_with($hostname, '.localhost')) {
            return null;
        }

        return substr($hostname, 0, -strlen('.localhost')).'.'.config('proxy.container_tld');
    }

    /**
     * Explain the second hostname, which is otherwise a surprise.
     */
    protected function reportContainerHostname(?string $containerHostname): void
    {
        if ($containerHostname === null) {
            return;
        }

        $this->newLine();
        $this->line("  From other containers, reach this app at http://{$containerHostname}");
        $this->newLine();
        $this->line("  Anything built on libcurl - including Guzzle and Laravel's HTTP client -");
        $this->line('  resolves *.localhost to 127.0.0.1 without asking DNS, so the .localhost');
        $this->line('  name would loop back to the calling container instead.');
        $this->newLine();
        $this->line('  https://github.com/tonysm/sail-proxy#container-to-container-requests');
        $this->newLine();
    }

    /**
     * The hostname to serve the app on, or null when the user declines.
     */
    protected function hostname(DockerCompose $compose, string $appService): ?string
    {
        if ($hostname = $this->option('host')) {
            return $hostname;
        }

        if (! $this->confirm("Register {$appService} with the proxy?", default: true)) {
            return null;
        }

        $default = basename($compose->path()).'.'.config('proxy.tld');

        return $this->ask('Hostname', $default) ?: null;
    }

    /**
     * The networks we attach the app service to, or a null entry when the
     * Takeout network was requested but does not exist.
     *
     * @return array<int, string|null>
     */
    protected function proxyNetworks(Docker $docker): array
    {
        $networks = [config('proxy.network.name')];

        if (! $this->option('takeout')) {
            return $networks;
        }

        $takeout = config('proxy.takeout_network');

        if (! $docker->networkExists($takeout)) {
            $this->error("Network '{$takeout}' does not exist.");
            $this->line('Takeout manages that network. Start a Takeout service first, for example:');
            $this->newLine();
            $this->line('  takeout enable mysql');

            return [...$networks, null];
        }

        return [...$networks, $takeout];
    }

    /**
     * Register the freshly started app with the proxy.
     */
    protected function register(DockerCompose $compose, string $appService, string $hostname): int
    {
        $container = $compose->containerFor($appService);

        if (! $container) {
            $this->error("Could not find running container for {$appService}.");
            $this->line("Start the project and then run 'sail-proxy register'.");

            return self::FAILURE;
        }

        return $this->call('register', [
            'container' => $container,
            'hostname' => $hostname,
            'port' => '80',
        ]);
    }
}
