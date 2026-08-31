<?php

namespace App\Commands;

use App\Support\Docker;
use Illuminate\Contracts\Process\ProcessResult;
use LaravelZero\Framework\Commands\Command;

class UninstallCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'uninstall {--force : Skip the confirmation}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Remove the proxy, network and volume';

    /**
     * Execute the console command.
     */
    public function handle(Docker $docker): int
    {
        $network = config('proxy.network.name');
        $proxy = config('proxy.proxy.name');

        // Read this before we start removing things: once the network is gone
        // there is no way to tell which projects were attached to it.
        $apps = array_values(array_diff(
            $docker->networkContainers($network),
            [$proxy],
        ));

        if (! $this->confirmRemoval($network, $proxy)) {
            return self::SUCCESS;
        }

        // "docker rm -f" exits 0 for a container that is not there, so ask
        // first rather than claim we removed something we did not.
        if ($docker->containerExists($proxy)) {
            $docker->forceRemove($proxy);

            $this->info("Removed {$proxy}.");
        }

        foreach ($apps as $app) {
            // A network cannot be removed while an app container still holds
            // an endpoint on it.
            if (! $this->reportFailure($docker->disconnect($network, $app), "disconnect '{$app}'")) {
                return self::FAILURE;
            }
        }

        if ($docker->networkExists($network)) {
            if (! $this->reportFailure($docker->removeNetwork($network), "remove network '{$network}'")) {
                return self::FAILURE;
            }

            $this->info("Removed the '{$network}' network.");
        }

        if ($docker->volumeExists($proxy)) {
            if (! $this->reportFailure($docker->removeVolume($proxy), "remove volume '{$proxy}'")) {
                return self::FAILURE;
            }

            $this->info("Removed the '{$proxy}' volume.");
        }

        $this->reportProjects($docker, $apps);

        return self::SUCCESS;
    }

    /**
     * Confirm the removal, listing what it covers.
     */
    protected function confirmRemoval(string $network, string $proxy): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->line('This removes:');
        $this->line("  container  {$proxy}");
        $this->line("  network    {$network}");
        $this->line("  volume     {$proxy}");
        $this->newLine();

        if ($this->confirm('Continue?', default: false)) {
            return true;
        }

        $this->line('Aborted.');

        return false;
    }

    /**
     * Surface a docker failure rather than reporting success.
     */
    protected function reportFailure(ProcessResult $result, string $action): bool
    {
        if ($result->successful()) {
            return true;
        }

        $this->error("Could not {$action}.");
        $this->line(trim($result->errorOutput()) ?: trim($result->output()));

        return false;
    }

    /**
     * Tell the user which projects still carry an override file.
     *
     * @param  array<int, string>  $apps
     */
    protected function reportProjects(Docker $docker, array $apps): void
    {
        $overrideFile = config('proxy.override_file');

        $projects = [];

        foreach ($apps as $app) {
            ['name' => $name, 'dir' => $dir] = $docker->composeProject($app);

            $projects[] = $dir
                ? [$name ?? $app, $dir.'/'.$overrideFile]
                : [$app, '(not a compose project)'];
        }

        $this->newLine();

        if ($projects !== []) {
            $this->line('These projects were configured for the proxy. Remove their override');
            $this->line('file before starting them again, or compose will fail on the missing');
            $this->line('network:');
            $this->newLine();

            $width = max(array_map(fn (array $p): int => strlen($p[0]), $projects));

            foreach ($projects as [$name, $path]) {
                $this->line('  '.str_pad($name, $width).'  '.$path);
            }

            $this->newLine();
        }

        $this->line('A project that is currently stopped could not be detected; if you');
        $this->line("configured others, remove their {$overrideFile} too.");
    }
}
