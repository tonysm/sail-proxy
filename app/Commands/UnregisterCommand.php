<?php

namespace App\Commands;

use App\Support\KamalProxy;
use LaravelZero\Framework\Commands\Command;

class UnregisterCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'unregister {app? : The app to stop serving}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Unregister an app from the proxy';

    /**
     * Execute the console command.
     */
    public function handle(KamalProxy $proxy): int
    {
        if (! $proxy->isRunning()) {
            $this->error('Proxy is not running.');

            return self::FAILURE;
        }

        $app = $this->argument('app');

        if (! $app) {
            $apps = $proxy->apps();

            if ($apps === []) {
                $this->error('No apps registered with the proxy.');

                return self::FAILURE;
            }

            $app = $this->choice('Select an app to unregister:', $apps);
        }

        $result = $proxy->remove($app);

        if ($result->failed()) {
            $this->error(trim($result->errorOutput()) ?: "Failed to unregister '{$app}'.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Unregistered: {$app}");

        return self::SUCCESS;
    }
}
