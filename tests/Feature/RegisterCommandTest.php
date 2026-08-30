<?php

use Illuminate\Support\Facades\Process;

it('fails when the proxy is not running', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'myapp.localhost'])
        ->expectsOutputToContain("Proxy is not running. Run 'sail-proxy install' first.")
        ->assertExitCode(1);
});

it('registers a container with the proxy', function () {
    fakeProcesses(['docker ps*' => Process::result("sail-proxy\nmyapp-laravel.test-1")]);

    $this->artisan('register', [
        'container' => 'myapp-laravel.test-1',
        'hostname' => 'myapp.localhost',
    ])->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy myapp --target myapp-laravel.test-1:80 --deploy-timeout 120s --host myapp.localhost');
});

it('honours a custom port', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'x.localhost', 'port' => '8080'])
        ->assertExitCode(0);

    assertRanProcess('* --target app:8080 *');
});

it('keeps the whole hostname as the app name when it is not a localhost host', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'myapp.test'])
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy myapp.test *');
});

it('asks for a container and hostname when they are not given', function () {
    fakeProcesses(['docker ps*' => Process::result("sail-proxy\nsail-dns\nweb")]);

    $this->artisan('register')
        ->expectsChoice('Select a container:', 'web', ['web'])
        ->expectsQuestion('Hostname', 'chosen.localhost')
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy chosen --target web:80 * --host chosen.localhost');
});

it('reports a failed deploy', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy deploy*' => Process::result(
            output: '', errorOutput: 'target failed to become healthy', exitCode: 1,
        ),
    ]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'x.localhost'])
        ->expectsOutputToContain('target failed to become healthy')
        ->assertExitCode(1);
});
