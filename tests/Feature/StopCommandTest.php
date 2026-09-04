<?php

use Illuminate\Support\Facades\Process;

it('stops a running proxy', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('stop')
        ->expectsOutputToContain('Proxy stopped.')
        ->expectsOutputToContain("It stays stopped across reboots. Run 'sail-proxy start' to serve them again.")
        ->assertExitCode(0);

    assertRanProcess('docker stop sail-proxy');
});

it('leaves the app containers alone', function () {
    fakeProcesses(['docker ps*' => Process::result("sail-proxy\nmyapp-laravel.test-1")]);

    $this->artisan('stop')->assertExitCode(0);

    assertDidntRunProcess('docker stop myapp-laravel.test-1');
});

it('has nothing to stop when the container is gone', function () {
    fakeProcesses([
        'docker container inspect*' => Process::result(exitCode: 1),
        'docker ps*' => Process::result('sail-proxy'),
    ]);

    $this->artisan('stop')
        ->expectsOutputToContain('Nothing to stop.')
        ->assertExitCode(0);

    assertDidntRunProcess('docker stop*');
});

it('leaves an already stopped proxy alone', function () {
    // The fake defaults give an existing container and an empty "docker ps".
    fakeProcesses();

    $this->artisan('stop')
        ->expectsOutputToContain('Proxy already stopped.')
        ->assertExitCode(0);

    assertDidntRunProcess('docker stop*');
});

it('removes nothing', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('stop')->assertExitCode(0);

    assertDidntRunProcess('docker rm*');
    assertDidntRunProcess('docker network rm*');
    assertDidntRunProcess('docker volume rm*');
});

it('reports a proxy that refused to stop', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker stop*' => Process::result(
            output: '', errorOutput: 'permission denied', exitCode: 1,
        ),
    ]);

    $this->artisan('stop')
        ->expectsOutputToContain("Could not stop 'sail-proxy'.")
        ->expectsOutputToContain('permission denied')
        ->assertExitCode(1);
});
