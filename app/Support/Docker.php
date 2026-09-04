<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Docker
{
    /**
     * Run a docker command.
     *
     * @param  array<int, string>  $args
     */
    public function run(array $args): ProcessResult
    {
        return Process::run(['docker', ...$args]);
    }

    /**
     * Run a command inside a running container.
     *
     * @param  array<int, string>  $args
     */
    public function exec(string $container, array $args): ProcessResult
    {
        return $this->run(['exec', $container, ...$args]);
    }

    /**
     * Determine if the given container is currently running.
     */
    public function isRunning(string $name): bool
    {
        return in_array($name, $this->containerNames(), strict: true);
    }

    /**
     * The names of every running container.
     *
     * @return array<int, string>
     */
    public function containerNames(): array
    {
        $result = $this->run(['ps', '--format', '{{.Names}}']);

        if ($result->failed()) {
            return [];
        }

        return $this->lines($result->output());
    }

    /**
     * Determine if the given network exists.
     */
    public function networkExists(string $name): bool
    {
        return $this->run(['network', 'inspect', $name])->successful();
    }

    /**
     * The names of the containers attached to the given network.
     *
     * @return array<int, string>
     */
    public function networkContainers(string $name): array
    {
        $result = $this->run([
            'network', 'inspect', $name,
            '--format', '{{range .Containers}}{{.Name}}{{"\n"}}{{end}}',
        ]);

        if ($result->failed()) {
            return [];
        }

        return $this->lines($result->output());
    }

    /**
     * Detach a container from a network.
     *
     * Forced, so that stopped containers are detached too: a network cannot be
     * removed while anything still holds an endpoint on it.
     */
    public function disconnect(string $network, string $container): ProcessResult
    {
        return $this->run(['network', 'disconnect', '-f', $network, $container]);
    }

    /**
     * Remove a network.
     */
    public function removeNetwork(string $name): ProcessResult
    {
        return $this->run(['network', 'rm', $name]);
    }

    /**
     * Determine if the given container exists, running or not.
     */
    public function containerExists(string $name): bool
    {
        return $this->run(['container', 'inspect', $name])->successful();
    }

    /**
     * Determine if the given volume exists.
     */
    public function volumeExists(string $name): bool
    {
        return $this->run(['volume', 'inspect', $name])->successful();
    }

    /**
     * Remove a volume.
     */
    public function removeVolume(string $name): ProcessResult
    {
        return $this->run(['volume', 'rm', $name]);
    }

    /**
     * Read a label off a container.
     */
    public function label(string $container, string $label): ?string
    {
        return $this->labels($container, [$label])[$label] ?? null;
    }

    /**
     * Read several labels off a container in one call.
     *
     * Null means the container itself is gone -- an empty label reads as a null
     * value inside the array, so the two cases stay apart.
     *
     * @param  array<int, string>  $labels
     * @return array<string, string|null>|null
     */
    public function labels(string $container, array $labels): ?array
    {
        $format = implode('{{"\n"}}', array_map(
            fn (string $label): string => '{{index .Config.Labels "'.$label.'"}}',
            $labels,
        ));

        $result = $this->run(['inspect', $container, '--format', $format]);

        if ($result->failed()) {
            return null;
        }

        // One line per label, in the order they were asked for, so they can't
        // be filtered or reordered the way lines() does. Only the trailing
        // newline comes off: an empty first label is a leading blank line, and
        // trimming that would shift every value up one.
        $values = array_map(trim(...), explode("\n", rtrim($result->output(), "\n")));
        $values = array_pad(array_slice($values, 0, count($labels)), count($labels), '');

        return array_map(
            fn (string $value): ?string => ($value === '' || $value === '<no value>') ? null : $value,
            array_combine($labels, $values),
        );
    }

    /**
     * The compose project a container belongs to, as its labels report it.
     *
     * @return array{name: string|null, dir: string|null}
     */
    public function composeProject(string $container): array
    {
        $labels = $this->labels($container, [
            'com.docker.compose.project',
            'com.docker.compose.project.working_dir',
        ]) ?? [];

        return [
            'name' => $labels['com.docker.compose.project'] ?? null,
            'dir' => $labels['com.docker.compose.project.working_dir'] ?? null,
        ];
    }

    /**
     * Create a network, letting Docker assign it a subnet.
     */
    public function createNetwork(string $name): ProcessResult
    {
        return $this->run(['network', 'create', $name]);
    }

    /**
     * Remove a container, ignoring the case where it does not exist.
     */
    public function forceRemove(string $name): void
    {
        $this->run(['rm', '-f', $name]);
    }

    /**
     * Split command output into non-empty trimmed lines.
     *
     * @return array<int, string>
     */
    protected function lines(string $output): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $output))));
    }
}
