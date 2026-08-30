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
     * The subnet configured for the given network, if any.
     */
    public function networkSubnet(string $name): ?string
    {
        $result = $this->run([
            'network', 'inspect', $name,
            '--format', '{{range .IPAM.Config}}{{.Subnet}}{{end}}',
        ]);

        if ($result->failed()) {
            return null;
        }

        return trim($result->output()) ?: null;
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
        $result = $this->run([
            'inspect', $container,
            '--format', '{{index .Config.Labels "'.$label.'"}}',
        ]);

        if ($result->failed()) {
            return null;
        }

        $value = trim($result->output());

        return ($value === '' || $value === '<no value>') ? null : $value;
    }

    /**
     * Create a network with the given subnet.
     */
    public function createNetwork(string $name, string $subnet): ProcessResult
    {
        return $this->run(['network', 'create', '--subnet', $subnet, $name]);
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
