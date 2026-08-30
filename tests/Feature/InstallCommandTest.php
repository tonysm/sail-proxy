<?php

use Illuminate\Support\Facades\Process;

it('creates the network when it does not exist', function () {
    fakeProcesses([
        'docker network inspect sail-proxy*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('install')->assertExitCode(0);

    assertRanProcess('docker network create --subnet 172.42.0.0/16 sail-proxy');
});

it('leaves an existing network with the expected subnet alone', function () {
    fakeProcesses([
        'docker network inspect sail-proxy --format*' => Process::result('172.42.0.0/16'),
    ]);

    $this->artisan('install')->assertExitCode(0);

    assertDidntRunProcess('docker network create*');
});

it('refuses to continue when the network has a different subnet', function () {
    fakeProcesses([
        'docker network inspect sail-proxy --format*' => Process::result('10.0.0.0/16'),
    ]);

    $this->artisan('install')
        ->expectsOutputToContain('has subnet 10.0.0.0/16 (expected 172.42.0.0/16)')
        ->assertExitCode(1);

    assertDidntRunProcess('docker run*');
});

it('starts the dns resolver and the proxy', function () {
    fakeProcesses([
        'docker network inspect sail-proxy --format*' => Process::result('172.42.0.0/16'),
        'docker ps*' => Process::result(''),
    ]);

    $this->artisan('install')->assertExitCode(0);

    assertRanProcess('docker run -d --name sail-dns --network sail-proxy --ip 172.42.255.253 * --address=/.localhost/172.42.255.254 --server=8.8.8.8');
    assertRanProcess('docker run -d --name sail-proxy --network sail-proxy --ip 172.42.255.254 * kamal-proxy run');
});

it('does not restart containers that are already running', function () {
    fakeProcesses([
        'docker network inspect sail-proxy --format*' => Process::result('172.42.0.0/16'),
        'docker ps*' => Process::result("sail-proxy\nsail-dns"),
    ]);

    $this->artisan('install')
        ->expectsOutputToContain('DNS already running.')
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
        'docker network inspect sail-proxy --format*' => Process::result('172.42.0.0/16'),
        'docker ps*' => Process::result(''),
        'docker run -d --name sail-dns*' => Process::result(
            output: '', errorOutput: 'address already in use', exitCode: 1,
        ),
    ]);

    $this->artisan('install')
        ->expectsOutputToContain("Could not start 'sail-dns'.")
        ->expectsOutputToContain('address already in use')
        ->assertExitCode(1);
});
