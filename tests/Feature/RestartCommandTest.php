<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('stops the proxy before starting it again', function () {
    $commands = [];
    $running = true;

    // Process::recorded() does not exist in this version, so record the order
    // from inside the fake -- which also has to model the proxy actually going
    // down, or "start" reads it as still running and skips.
    Process::fake(function ($process) use (&$commands, &$running) {
        $command = processCommand($process);

        $commands[] = $command;

        if (Str::is('docker stop*', $command)) {
            $running = false;
        }

        return Str::is('docker ps*', $command)
            ? Process::result($running ? 'sail-proxy' : '')
            : Process::result();
    });

    $this->artisan('restart')
        ->doesntExpectOutputToContain('It stays stopped across reboots')
        ->assertExitCode(0);

    $commands = array_values(array_filter(
        $commands,
        fn (string $command): bool => Str::is(['docker stop*', 'docker start*'], $command),
    ));

    expect($commands)->toBe(['docker stop sail-proxy', 'docker start sail-proxy']);
});

it('starts a proxy that was already stopped', function () {
    // The fake defaults give an existing container and an empty "docker ps".
    fakeProcesses();

    $this->artisan('restart')
        ->expectsOutputToContain('Proxy already stopped.')
        ->assertExitCode(0);

    assertRanProcess('docker start sail-proxy');
    assertDidntRunProcess('docker stop*');
});

it('points at install when there is no container', function () {
    fakeProcesses(['docker container inspect*' => Process::result(exitCode: 1)]);

    $this->artisan('restart')
        ->expectsOutputToContain("Proxy is not installed. Run 'sail-proxy install' first.")
        ->assertExitCode(1);

    assertDidntRunProcess('docker start*');
    assertDidntRunProcess('docker stop*');
});
