<?php

use Illuminate\Support\Facades\Process;

it('creates the network when it does not exist', function () {
    fakeProcesses([
        'docker network inspect sail-proxy*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('install')->assertExitCode(0);

    // Docker picks the subnet: we pin nothing, so there is nothing to collide.
    assertRanProcess('docker network create sail-proxy');
});

it('leaves an existing network alone', function () {
    fakeProcesses();

    $this->artisan('install')->assertExitCode(0);

    assertDidntRunProcess('docker network create*');
});

it('starts the proxy', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    $this->artisan('install')->assertExitCode(0);

    assertRanProcess('docker run -d --name sail-proxy --network sail-proxy * kamal-proxy run');
});

it('starts no other container', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    $this->artisan('install')->assertExitCode(0);

    // Apps resolve each other through network aliases, so there is no DNS
    // container to run any more.
    assertDidntRunProcess('docker run -d --name sail-dns*');
});

it('does not pin the proxy to an address', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    $this->artisan('install')->assertExitCode(0);

    assertDidntRunProcess('docker run * --ip *');
});

it('does not restart a proxy that is already running', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('install')
        ->expectsOutputToContain('Proxy already running.')
        ->assertExitCode(0);

    assertDidntRunProcess('docker run*');
});

it('reports a network that could not be created instead of claiming success', function () {
    fakeProcesses([
        'docker network inspect sail-proxy*' => Process::result(exitCode: 1),
        'docker network create*' => Process::result(
            output: '', errorOutput: 'Pool overlaps with other one on this address space', exitCode: 1,
        ),
    ]);

    $this->artisan('install')
        ->expectsOutputToContain("Could not create network 'sail-proxy'.")
        ->expectsOutputToContain('Pool overlaps with other one on this address space')
        ->assertExitCode(1);

    assertDidntRunProcess('docker run*');
});

it('reports a container that refused to start', function () {
    fakeProcesses([
        'docker ps*' => Process::result(''),
        'docker run -d --name sail-proxy*' => Process::result(
            output: '', errorOutput: 'address already in use', exitCode: 1,
        ),
    ]);

    $this->artisan('install')
        ->expectsOutputToContain("Could not start 'sail-proxy'.")
        ->expectsOutputToContain('address already in use')
        ->assertExitCode(1);
});
