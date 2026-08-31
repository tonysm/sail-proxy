<?php

namespace App\Commands;

use App\Support\Docker;
use Illuminate\Contracts\Process\ProcessResult;
use LaravelZero\Framework\Commands\Command;

class InstallCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'install';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Start the proxy container';

    /**
     * Execute the console command.
     */
    public function handle(Docker $docker): int
    {
        if (! $this->ensureNetwork($docker)) {
            return self::FAILURE;
        }

        if (! $this->ensureProxy($docker)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Proxy is running.');
        $this->newLine();
        $this->line('  To configure a Sail app, run from the app directory:');
        $this->newLine();
        $this->line('    sail-proxy config');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Create the proxy network if it is not already there.
     */
    protected function ensureNetwork(Docker $docker): bool
    {
        $name = config('proxy.network.name');

        if ($docker->networkExists($name)) {
            return true;
        }

        $result = $docker->createNetwork($name);

        if ($result->failed()) {
            $this->error("Could not create network '{$name}'.");
            $this->line(trim($result->errorOutput()));

            return false;
        }

        $this->info("Network '{$name}' created.");

        return true;
    }

    /**
     * Start the proxy itself.
     */
    protected function ensureProxy(Docker $docker): bool
    {
        $name = config('proxy.proxy.name');

        if ($docker->isRunning($name)) {
            $this->info('Proxy already running.');

            return true;
        }

        $docker->forceRemove($name);

        $metricsPort = (string) config('proxy.proxy.metrics_port');

        $result = $docker->run([
            'run', '-d',
            '--name', $name,
            '--network', config('proxy.network.name'),
            '--restart', 'unless-stopped',
            '-p', '80:80',
            '-p', "{$metricsPort}:{$metricsPort}",
            '-e', "KAMAL_PROXY_METRICS_PORT={$metricsPort}",
            '--mount', "type=volume,source={$name},target=/home/kamal-proxy/.config/kamal-proxy",
            config('proxy.proxy.image'),
            'kamal-proxy', 'run',
        ]);

        if ($result->failed()) {
            return $this->reportStartFailure($name, $result);
        }

        $this->info('Proxy started.');

        return true;
    }

    /**
     * Report a container that refused to start.
     */
    protected function reportStartFailure(string $name, ProcessResult $result): bool
    {
        $this->error("Could not start '{$name}'.");
        $this->line(trim($result->errorOutput()) ?: trim($result->output()));

        return false;
    }
}
