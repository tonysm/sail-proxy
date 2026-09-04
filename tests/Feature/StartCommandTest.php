<?php

use Illuminate\Support\Facades\Process;

it('starts a stopped proxy', function () {
    // The fake defaults give an existing container and an empty "docker ps".
    fakeProcesses();

    $this->artisan('start')
        ->expectsOutputToContain('Proxy started.')
        ->assertExitCode(0);

    assertRanProcess('docker start sail-proxy');
});

it('points at install when there is no container to start', function () {
    fakeProcesses(['docker container inspect*' => Process::result(exitCode: 1)]);

    $this->artisan('start')
        ->expectsOutputToContain("Proxy is not installed. Run 'sail-proxy install' first.")
        ->assertExitCode(1);

    assertDidntRunProcess('docker start*');
});

it('creates nothing of its own', function () {
    fakeProcesses(['docker container inspect*' => Process::result(exitCode: 1)]);

    $this->artisan('start')->assertExitCode(1);

    assertDidntRunProcess('docker run*');
    assertDidntRunProcess('docker network create*');
});

it('leaves a running proxy alone', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('start')
        ->expectsOutputToContain('Proxy already running.')
        ->assertExitCode(0);

    assertDidntRunProcess('docker start*');
});

it('reports a proxy that refused to start', function () {
    fakeProcesses([
        'docker start*' => Process::result(
            output: '', errorOutput: 'driver failed programming external connectivity', exitCode: 1,
        ),
    ]);

    $this->artisan('start')
        ->expectsOutputToContain("Could not start 'sail-proxy'.")
        ->expectsOutputToContain('driver failed programming external connectivity')
        ->assertExitCode(1);
});
