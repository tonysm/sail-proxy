<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class RestartCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'restart';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restart the proxy container';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // "stop" succeeds when there is no container, so a missing one still
        // ends at "start", which is where that message belongs.
        return $this->call('stop', ['--brief' => true]) === self::SUCCESS
            ? $this->call('start')
            : self::FAILURE;
    }
}
