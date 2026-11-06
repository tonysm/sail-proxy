<?php

use Illuminate\Support\Facades\Artisan;
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
    ])
        ->expectsQuestion('Port', '80')
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy myapp --target myapp-laravel.test-1:80 --deploy-timeout 120s --host myapp.localhost');
});

it('asks for the port when only the container and hostname are given', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'x.localhost'])
        ->expectsQuestion('Port', '3000')
        ->assertExitCode(0);

    assertRanProcess('* --target app:3000 *');
});

it('falls back to port 80 when it cannot ask', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $exitCode = Artisan::call('register', [
        'container' => 'app',
        'hostname' => 'x.localhost',
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);

    assertRanProcess('* --target app:80 *');
});

it('honours a custom port', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'x.localhost', 'port' => '8080'])
        ->assertExitCode(0);

    assertRanProcess('* --target app:8080 *');
});

it('keeps the whole hostname as the app name when it is not a localhost host', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'myapp.test', 'port' => '80'])
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy myapp.test *');
});

it('asks for a container, hostname and port when they are not given', function () {
    fakeProcesses(['docker ps*' => Process::result("sail-proxy\nweb")]);

    $this->artisan('register')
        ->expectsChoice('Select a container:', 'web', ['web'])
        ->expectsQuestion('Hostname', 'chosen.localhost')
        ->expectsQuestion('Port', '8080')
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy chosen --target web:8080 * --host chosen.localhost');
});

it('reports a failed deploy', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy deploy*' => Process::result(
            output: '', errorOutput: 'target failed to become healthy', exitCode: 1,
        ),
    ]);

    $this->artisan('register', ['container' => 'app', 'hostname' => 'x.localhost', 'port' => '80'])
        ->expectsOutputToContain('target failed to become healthy')
        ->assertExitCode(1);
});
