<?php

namespace App\Commands;

use App\Support\Docker;
use App\Support\DockerCompose;
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
                            {--force : Overwrite an existing override file without asking}';

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

        file_put_contents($path, $override->render($services, $appService, $networks, $external));

        $this->info('Created '.config('proxy.override_file'));

        $compose->down();
        $compose->up();

        if (! $proxy->isRunning()) {
            $this->newLine();
            $this->warn("Proxy is not running. Run 'sail-proxy install' first, then 'sail-proxy register'.");

            return self::SUCCESS;
        }

        return $this->register($compose, $appService);
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
     * Offer to register the freshly started app with the proxy.
     */
    protected function register(DockerCompose $compose, string $appService): int
    {
        $hostname = $this->option('host');

        if (! $hostname) {
            if (! $this->confirm("Register {$appService} with the proxy?", default: true)) {
                return self::SUCCESS;
            }

            $default = basename($compose->path()).'.'.config('proxy.tld');

            $hostname = $this->ask('Hostname', $default);
        }

        if (! $hostname) {
            $this->error('Hostname is required.');

            return self::FAILURE;
        }

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
