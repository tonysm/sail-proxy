<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;

class KamalProxy
{
    public function __construct(protected Docker $docker)
    {
        //
    }

    /**
     * The name of the container running the proxy.
     */
    public function container(): string
    {
        return config('proxy.proxy.name');
    }

    /**
     * Determine if the proxy is currently running.
     */
    public function isRunning(): bool
    {
        return $this->docker->isRunning($this->container());
    }

    /**
     * Point a hostname at a container.
     */
    public function deploy(string $app, string $target, string $host): ProcessResult
    {
        return $this->docker->exec($this->container(), [
            'kamal-proxy', 'deploy', $app,
            '--target', $target,
            '--deploy-timeout', config('proxy.deploy_timeout'),
            '--host', $host,
        ]);
    }

    /**
     * Stop routing traffic for the given app.
     */
    public function remove(string $app): ProcessResult
    {
        return $this->docker->exec($this->container(), ['kamal-proxy', 'remove', $app]);
    }

    /**
     * The apps currently registered with the proxy.
     *
     * @return array<int, string>
     */
    public function apps(): array
    {
        $result = $this->docker->exec($this->container(), ['kamal-proxy', 'list']);

        if ($result->failed()) {
            return [];
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $result->output()))));

        // The first line is the table header.
        return array_values(array_filter(array_map(
            fn (string $line): string => (string) strtok($line, " \t"),
            array_slice($lines, 1),
        )));
    }
}
