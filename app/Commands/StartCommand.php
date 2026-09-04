<?php

namespace App\Commands;

use App\Support\KamalProxy;
use Illuminate\Contracts\Process\ProcessResult;
use LaravelZero\Framework\Commands\Command;

class StartCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'start';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Start the proxy container';

    /**
     * Execute the console command.
     */
    public function handle(KamalProxy $proxy): int
    {
        // Starting is not installing: the container, network and volume are
        // "install"'s to create.
        if (! $proxy->exists()) {
            $this->error("Proxy is not installed. Run 'sail-proxy install' first.");

            return self::FAILURE;
        }

        if ($proxy->isRunning()) {
            $this->info('Proxy already running.');

            return self::SUCCESS;
        }

        $result = $proxy->start();

        if ($result->failed()) {
            return $this->reportFailure($proxy, $result);
        }

        $this->info('Proxy started.');

        return self::SUCCESS;
    }

    /**
     * Report a container that refused to start.
     */
    protected function reportFailure(KamalProxy $proxy, ProcessResult $result): int
    {
        $this->error("Could not start '{$proxy->container()}'.");
        $this->line(trim($result->errorOutput()) ?: trim($result->output()));

        return self::FAILURE;
    }
}
