<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class DockerCompose
{
    /**
     * The compose file names we look for, in order of preference.
     *
     * @var array<int, string>
     */
    protected const CANDIDATES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ];

    public function __construct(protected string $path)
    {
        //
    }

    /**
     * The project directory this instance operates on.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The compose file in the project directory, if there is one.
     */
    public function composeFile(): ?string
    {
        foreach (static::CANDIDATES as $candidate) {
            if (is_file($this->path.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The base command used to drive compose, preferring Sail when available.
     *
     * @return array<int, string>
     */
    public function command(): array
    {
        if (is_file($this->path.'/vendor/bin/sail')) {
            return ['./vendor/bin/sail'];
        }

        return ['docker', 'compose'];
    }

    /**
     * The name of the application service, as configured by Sail.
     */
    public function appService(): string
    {
        $default = config('proxy.default_service');

        if (! is_file($env = $this->path.'/.env')) {
            return $default;
        }

        if (! preg_match('/^APP_SERVICE=(.*)$/m', (string) file_get_contents($env), $matches)) {
            return $default;
        }

        $service = trim(trim($matches[1]), '"\'');

        return $service !== '' ? $service : $default;
    }

    /**
     * Every service defined in the project, as compose reports it.
     */
    public function services(): ProcessResult
    {
        return $this->inspect(['config', '--services']);
    }

    /**
     * The networks the given service is already attached to.
     *
     * @return array<int, string>
     */
    public function networksFor(string $service): array
    {
        $result = $this->inspect(['config', '--format', 'json']);

        if ($result->failed()) {
            return [];
        }

        $config = json_decode($result->output(), associative: true);

        $networks = $config['services'][$service]['networks'] ?? [];

        return array_values(array_map(strval(...), array_keys($networks)));
    }

    /**
     * Inspect the project as it is defined without us.
     *
     * We name the base compose file explicitly so that the override file we
     * are about to write is left out. Reading the merged config instead would
     * feed our own previous output back in, and every re-run would accumulate
     * the networks it added the time before.
     *
     * @param  array<int, string>  $args
     */
    public function inspect(array $args): ProcessResult
    {
        $file = $this->composeFile();

        return $this->run($file ? ['-f', $file, ...$args] : $args);
    }

    /**
     * The name of the running container backing the given service.
     */
    public function containerFor(string $service): ?string
    {
        $result = $this->run(['ps', '--format', '{{.Names}}', $service]);

        if ($result->failed()) {
            return null;
        }

        $names = array_values(array_filter(array_map(trim(...), explode("\n", $result->output()))));

        return $names[0] ?? null;
    }

    /**
     * Stop the project.
     */
    public function down(): ProcessResult
    {
        return $this->run(['down']);
    }

    /**
     * Start the project in the background.
     */
    public function up(): ProcessResult
    {
        return $this->run(['up', '-d']);
    }

    /**
     * Run a compose command in the project directory.
     *
     * @param  array<int, string>  $args
     */
    public function run(array $args): ProcessResult
    {
        return Process::path($this->path)->run([...$this->command(), ...$args]);
    }
}
