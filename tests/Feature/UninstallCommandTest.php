<?php

use Illuminate\Support\Facades\Process;

/**
 * Fake a network holding our own containers plus the given app containers.
 *
 * @param  array<int, string>  $apps
 * @return array<string, mixed>
 */
function installed(array $apps = []): array
{
    return [
        'docker network inspect sail-proxy --format {{range .Containers}}*' => Process::result(
            implode("\n", ['sail-proxy', 'sail-dns', ...$apps]),
        ),
        'docker network inspect sail-proxy' => Process::result('[]'),
        'docker volume inspect sail-proxy' => Process::result('[]'),
        'docker container inspect *' => Process::result('[]'),
    ];
}

it('removes the containers, the network and the volume', function () {
    fakeProcesses(installed());

    $this->artisan('uninstall', ['--force' => true])->assertExitCode(0);

    assertRanProcess('docker rm -f sail-proxy');
    assertRanProcess('docker rm -f sail-dns');
    assertRanProcess('docker network rm sail-proxy');
    assertRanProcess('docker volume rm sail-proxy');
});

it('force-disconnects app containers before removing the network', function () {
    $ran = [];

    Process::fake(function ($process) use (&$ran) {
        $command = processCommand($process);

        $ran[] = $command;

        return str_contains($command, '{{range .Containers}}')
            ? Process::result("sail-proxy\nsail-dns\nhotwire-laravel.test-1\nblog-laravel.test-1")
            : Process::result('[]');
    });

    $this->artisan('uninstall', ['--force' => true])->assertExitCode(0);

    expect($ran)
        ->toContain('docker network disconnect -f sail-proxy hotwire-laravel.test-1')
        ->toContain('docker network disconnect -f sail-proxy blog-laravel.test-1');

    // The network cannot be removed while an app still holds an endpoint on
    // it, so every disconnect has to happen before the removal.
    $lastDisconnect = max(array_keys(array_filter(
        $ran,
        fn (string $c): bool => str_starts_with($c, 'docker network disconnect'),
    )));

    expect($lastDisconnect)->toBeLessThan(array_search('docker network rm sail-proxy', $ran, true));
});

it('never disconnects its own containers', function () {
    fakeProcesses(installed(['app-1']));

    $this->artisan('uninstall', ['--force' => true])->assertExitCode(0);

    assertDidntRunProcess('docker network disconnect * sail-proxy');
    assertDidntRunProcess('docker network disconnect * sail-dns');
});

it('names the projects whose override file still needs removing', function () {
    fakeProcesses([
        ...installed(['hotwire-laravel.test-1']),
        'docker inspect hotwire-laravel.test-1 --format {{index .Config.Labels "com.docker.compose.project"}}' => Process::result('hotwire-starter-kit'),
        'docker inspect hotwire-laravel.test-1 --format {{index .Config.Labels "com.docker.compose.project.working_dir"}}' => Process::result('/home/tony/Code/hotwire-starter-kit'),
    ]);

    $this->artisan('uninstall', ['--force' => true])
        ->expectsOutputToContain('hotwire-starter-kit  /home/tony/Code/hotwire-starter-kit/compose.override.yaml')
        ->expectsOutputToContain('A project that is currently stopped could not be detected')
        ->assertExitCode(0);
});

it('falls back to the container name when there are no compose labels', function () {
    fakeProcesses([
        ...installed(['some-container']),
        'docker inspect some-container --format*' => Process::result('<no value>'),
    ]);

    $this->artisan('uninstall', ['--force' => true])
        ->expectsOutputToContain('some-container  (not a compose project)')
        ->assertExitCode(0);
});

it('is idempotent when nothing is installed', function () {
    fakeProcesses([
        'docker network inspect*' => Process::result(exitCode: 1),
        'docker volume inspect*' => Process::result(exitCode: 1),
        'docker container inspect*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('uninstall', ['--force' => true])
        ->doesntExpectOutputToContain('Removed')
        ->assertExitCode(0);

    assertDidntRunProcess('docker rm -f*');
    assertDidntRunProcess('docker network rm*');
    assertDidntRunProcess('docker volume rm*');
});

it('touches nothing when the confirmation is declined', function () {
    fakeProcesses(installed(['app-1']));

    $this->artisan('uninstall')
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    assertDidntRunProcess('docker rm -f*');
    assertDidntRunProcess('docker network rm*');
    assertDidntRunProcess('docker volume rm*');
});

it('proceeds when the confirmation is accepted', function () {
    fakeProcesses(installed());

    $this->artisan('uninstall')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    assertRanProcess('docker network rm sail-proxy');
});

it('surfaces a docker failure instead of reporting success', function () {
    fakeProcesses([
        ...installed(),
        'docker network rm sail-proxy' => Process::result(
            output: '', errorOutput: 'network sail-proxy has active endpoints', exitCode: 1,
        ),
    ]);

    $this->artisan('uninstall', ['--force' => true])
        ->expectsOutputToContain("Could not remove network 'sail-proxy'.")
        ->expectsOutputToContain('has active endpoints')
        ->assertExitCode(1);

    assertDidntRunProcess('docker volume rm*');
});
