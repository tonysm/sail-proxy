<?php

namespace App\Commands;

use App\Support\Docker;
use App\Support\KamalProxy;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

class StatusCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'status {--json : Output the proxy status as JSON}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Show the proxy state and the apps registered with it';

    /**
     * Execute the console command.
     */
    public function handle(KamalProxy $proxy, Docker $docker): int
    {
        $active = $proxy->isRunning();

        // A running container is an installed one, so only a proxy that isn't
        // running is worth another round trip to docker.
        $installed = $active || $proxy->exists();

        $projects = $active
            ? array_map(fn (array $service): array => $this->locate($docker, $service), $proxy->services())
            : [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'installed' => $installed,
                'active' => $active,
                'projects' => $projects,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  Proxy: '.($active ? 'on' : 'off').($installed ? '' : ' (not installed)'));
        $this->newLine();

        if (! $active) {
            $this->line($installed
                ? "  Run 'sail-proxy start' to start it."
                : "  Run 'sail-proxy install' to set it up.");
            $this->newLine();

            return self::SUCCESS;
        }

        if ($projects === []) {
            $this->line('  No apps registered with the proxy.');
            $this->newLine();

            return self::SUCCESS;
        }

        table(
            ['Service', 'Host', 'Target', 'State', 'Directory'],
            array_map(fn (array $project): array => [
                $project['service'] ?? '',
                $project['host'] ?? '',
                $project['target'] ?? '',
                $project['exists'] ? ($project['state'] ?? '') : 'gone',
                $this->directory($project),
            ], $projects),
        );

        return self::SUCCESS;
    }

    /**
     * Add the container behind a registration, and where it runs from.
     *
     * Compose labels every container it creates with the project name and the
     * host directory it was started from; anything started outside compose --
     * including whatever "register" was pointed at -- carries neither.
     *
     * @param  array<string, string>  $service
     * @return array<string, mixed>
     */
    protected function locate(Docker $docker, array $service): array
    {
        $container = $this->container($service['target'] ?? '');

        $labels = $container === '' ? null : $docker->labels($container, [
            'com.docker.compose.project',
            'com.docker.compose.project.working_dir',
        ]);

        return [
            ...$service,
            'container' => $container,
            // The proxy keeps routing to a container that has been removed, so
            // its own "state" column can outlive the thing it describes.
            'exists' => $labels !== null,
            'project' => $labels['com.docker.compose.project'] ?? null,
            'dir' => $labels['com.docker.compose.project.working_dir'] ?? null,
        ];
    }

    /**
     * The container a target points at, without its port.
     */
    protected function container(string $target): string
    {
        $port = strrpos($target, ':');

        return $port === false ? $target : substr($target, 0, $port);
    }

    /**
     * The directory column: a home-relative path, or why there isn't one.
     *
     * @param  array<string, mixed>  $project
     */
    protected function directory(array $project): string
    {
        if (! $project['exists']) {
            return 'container not found';
        }

        if (! $project['dir']) {
            return '-';
        }

        $home = (string) (getenv('HOME') ?: '');

        return $home !== '' && str_starts_with($project['dir'], $home.'/')
            ? '~'.substr($project['dir'], strlen($home))
            : $project['dir'];
    }
}
