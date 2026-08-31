<?php

namespace App\Commands;

use App\Support\Docker;
use App\Support\KamalProxy;
use LaravelZero\Framework\Commands\Command;

class RegisterCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'register
                            {container? : The container to route traffic to}
                            {hostname? : The hostname to serve it on}
                            {port=80 : The port the container listens on}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Register a running container with the proxy';

    /**
     * Execute the console command.
     */
    public function handle(Docker $docker, KamalProxy $proxy): int
    {
        if (! $proxy->isRunning()) {
            $this->error("Proxy is not running. Run 'sail-proxy install' first.");

            return self::FAILURE;
        }

        $container = $this->argument('container');

        if (! $container) {
            $containers = $this->selectableContainers($docker);

            if ($containers === []) {
                $this->error('No running containers found.');

                return self::FAILURE;
            }

            $container = $this->choice('Select a container:', $containers);
        }

        $hostname = $this->argument('hostname')
            ?: $this->ask('Hostname', 'myapp.'.config('proxy.tld'));

        if (! $hostname) {
            $this->error('Hostname is required.');

            return self::FAILURE;
        }

        $port = $this->argument('port') ?: '80';

        $app = $this->appName($hostname);

        $result = $proxy->deploy($app, "{$container}:{$port}", $hostname);

        if ($result->failed()) {
            $this->error(trim($result->errorOutput()) ?: 'Failed to register the app with the proxy.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Registered: http://{$hostname} -> {$container}:{$port}");

        return self::SUCCESS;
    }

    /**
     * The running containers, minus our own.
     *
     * @return array<int, string>
     */
    protected function selectableContainers(Docker $docker): array
    {
        $proxy = config('proxy.proxy.name');

        $containers = array_values(array_filter(
            $docker->containerNames(),
            fn (string $name): bool => $name !== $proxy,
        ));

        sort($containers);

        return $containers;
    }

    /**
     * Derive the proxy app name from the hostname.
     */
    protected function appName(string $hostname): string
    {
        $suffix = '.'.config('proxy.tld');

        return str_ends_with($hostname, $suffix)
            ? substr($hostname, 0, -strlen($suffix))
            : $hostname;
    }
}
