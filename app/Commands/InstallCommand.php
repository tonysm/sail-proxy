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
    protected $description = 'Start the proxy and DNS containers';

    /**
     * Execute the console command.
     */
    public function handle(Docker $docker): int
    {
        if (! $this->ensureNetwork($docker)) {
            return self::FAILURE;
        }

        if (! $this->ensureDns($docker) || ! $this->ensureProxy($docker)) {
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
     * Create the proxy network, or verify the existing one matches our subnet.
     */
    protected function ensureNetwork(Docker $docker): bool
    {
        $name = config('proxy.network.name');
        $subnet = config('proxy.network.subnet');

        if (! $docker->networkExists($name)) {
            $result = $docker->createNetwork($name, $subnet);

            if ($result->failed()) {
                $this->error("Could not create network '{$name}'.");
                $this->line(trim($result->errorOutput()));

                return false;
            }

            $this->info("Network '{$name}' created.");

            return true;
        }

        $actual = $docker->networkSubnet($name);

        if ($actual === $subnet) {
            return true;
        }

        $this->error("Network '{$name}' exists but has subnet {$actual} (expected {$subnet}).");
        $this->newLine();
        $this->line('To fix this, remove the existing network and re-run this command:');
        $this->newLine();
        $this->line("  docker network rm {$name}");
        $this->newLine();
        $this->line('If containers are still using it, stop them first:');
        $this->newLine();
        $this->line("  docker network disconnect {$name} \$(docker network inspect {$name} --format '{{range .Containers}}{{.Name}} {{end}}')");
        $this->line("  docker network rm {$name}");

        return false;
    }

    /**
     * Start the DNS resolver that points *.localhost at the proxy.
     */
    protected function ensureDns(Docker $docker): bool
    {
        $name = config('proxy.dns.name');

        if ($docker->isRunning($name)) {
            $this->info('DNS already running.');

            return true;
        }

        $docker->forceRemove($name);

        $result = $docker->run([
            'run', '-d',
            '--name', $name,
            '--network', config('proxy.network.name'),
            '--ip', config('proxy.dns.ip'),
            '--restart', 'unless-stopped',
            '--cap-add', 'NET_ADMIN',
            config('proxy.dns.image'),
            '--no-daemon',
            '--address=/.'.config('proxy.tld').'/'.config('proxy.proxy.ip'),
            '--server='.config('proxy.dns.upstream'),
        ]);

        if ($result->failed()) {
            return $this->reportStartFailure($name, $result);
        }

        $this->info('DNS started at '.config('proxy.dns.ip').'.');

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
            '--ip', config('proxy.proxy.ip'),
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
