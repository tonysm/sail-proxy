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
     * Determine if the proxy container is there at all, running or not.
     */
    public function exists(): bool
    {
        return $this->docker->containerExists($this->container());
    }

    /**
     * Start the proxy container.
     */
    public function start(): ProcessResult
    {
        return $this->docker->start($this->container());
    }

    /**
     * Stop the proxy container.
     */
    public function stop(): ProcessResult
    {
        return $this->docker->stop($this->container());
    }

    /**
     * Point a hostname at a container.
     *
     * The proxy only routes to a target once a health check has passed, which
     * by default is a 200 from /up on the target port. Anything that doesn't
     * answer there needs a different path or port, as kamal-proxy's own --force
     * won't help: it stops waiting for the check, but a target that never
     * passes one is left serving 503s while still listed as running.
     *
     * kamal-proxy gives up by itself after --deploy-timeout, so we wait for it
     * to say so rather than cut it off with a time limit of our own.
     */
    public function deploy(
        string $app,
        string $target,
        string $host,
        ?string $healthPath = null,
        ?string $healthPort = null,
    ): ProcessResult {
        return $this->docker->execWithoutTimeout($this->container(), [
            'kamal-proxy', 'deploy', $app,
            '--target', $target,
            '--deploy-timeout', config('proxy.deploy_timeout'),
            '--host', $host,
            ...($healthPath === null ? [] : ['--health-check-path', $healthPath]),
            ...($healthPort === null ? [] : ['--health-check-port', $healthPort]),
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
        return array_column($this->services(), 'service');
    }

    /**
     * Every service the proxy is routing, one row per registered app.
     *
     * kamal-proxy renders a padded table, and colours every cell whether or not
     * it is talking to a terminal, so the escape codes have to come off before
     * anything can be read out of it.
     *
     * @return array<int, array<string, string>>
     */
    public function services(): array
    {
        $result = $this->docker->exec($this->container(), ['kamal-proxy', 'list']);

        if ($result->failed()) {
            return [];
        }

        $lines = array_values(array_filter(array_map(
            trim(...),
            explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $result->output())),
        )));

        // The first line is the table header, and names the keys.
        $headers = array_map(strtolower(...), $this->cells(array_shift($lines) ?? ''));

        return array_map(function (string $line) use ($headers): array {
            // Pad the row out to the header, so a column we couldn't read
            // costs us that value and not the whole row.
            $cells = array_slice($this->cells($line), 0, count($headers));
            $cells = array_pad($cells, count($headers), '');

            return array_combine($headers, $cells);
        }, $lines);
    }

    /**
     * Split a row of the table into its cells.
     *
     * Columns are padded apart by at least two spaces, so that is the seam --
     * a single space is part of a value.
     *
     * @return array<int, string>
     */
    protected function cells(string $line): array
    {
        return preg_split('/\s{2,}/', trim($line)) ?: [];
    }
}
