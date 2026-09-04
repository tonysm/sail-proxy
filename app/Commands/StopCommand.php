<?php

namespace App\Commands;

use App\Support\KamalProxy;
use Illuminate\Contracts\Process\ProcessResult;
use LaravelZero\Framework\Commands\Command;

class StopCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'stop {--brief : Skip the follow-up notes}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Stop the proxy container';

    /**
     * Execute the console command.
     */
    public function handle(KamalProxy $proxy): int
    {
        // A container that isn't there is the state we were asked for, so this
        // is not a failure.
        if (! $proxy->exists()) {
            $this->info('Nothing to stop.');

            return self::SUCCESS;
        }

        if (! $proxy->isRunning()) {
            $this->info('Proxy already stopped.');

            return self::SUCCESS;
        }

        $result = $proxy->stop();

        if ($result->failed()) {
            return $this->reportFailure($proxy, $result);
        }

        $this->info('Proxy stopped.');

        // "restart" passes --brief, since it is about to start the proxy again
        // and neither note would be true by the time they were read.
        if (! $this->option('brief')) {
            $this->newLine();
            $this->line('  Your apps keep running; they just are not served on their hostnames.');
            // An explicit stop outranks the container's "unless-stopped"
            // restart policy, so it stays down until something starts it again.
            $this->line("  It stays stopped across reboots. Run 'sail-proxy start' to serve them again.");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Report a container that refused to stop.
     */
    protected function reportFailure(KamalProxy $proxy, ProcessResult $result): int
    {
        $this->error("Could not stop '{$proxy->container()}'.");
        $this->line(trim($result->errorOutput()) ?: trim($result->output()));

        return self::FAILURE;
    }
}
