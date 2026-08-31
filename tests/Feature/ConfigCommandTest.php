<?php

use App\Support\DockerCompose;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/sail-proxy-test-'.bin2hex(random_bytes(6));

    mkdir($this->project);

    $this->app->singleton(DockerCompose::class, fn () => new DockerCompose($this->project));
});

afterEach(function () {
    foreach (glob($this->project.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->project);
});

/**
 * Give the temp project a compose file, and fake compose to describe it.
 *
 * @param  array<int, string>  $services
 * @param  array<int, string>  $networks  networks the app service already has
 */
function project(array $services = ['laravel.test', 'redis'], array $networks = ['sail']): array
{
    $config = ['services' => []];

    foreach ($services as $service) {
        $config['services'][$service] = [
            'networks' => array_fill_keys($service === $services[0] ? $networks : ['default'], null),
        ];
    }

    return [
        'docker compose*config --services' => Process::result(implode("\n", $services)),
        'docker compose*config --format json' => Process::result(json_encode($config)),
    ];
}

it('fails when there is no compose file', function () {
    fakeProcesses();

    $this->artisan('config')
        ->expectsOutputToContain('No compose file found in the current directory.')
        ->assertExitCode(1);
});

it('fails when the app service is not in the compose file', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(project(services: ['redis', 'mysql']));

    $this->artisan('config')
        ->expectsOutputToContain("Service 'laravel.test' not found in compose.yaml.")
        ->expectsOutputToContain('Set APP_SERVICE in .env to the correct service name.')
        ->assertExitCode(1);
});

it('honours APP_SERVICE from the env file', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "APP_NAME=Test\nAPP_SERVICE=\"my.app\"\n");

    fakeProcesses([
        ...project(services: ['my.app'], networks: []),
        'docker ps*' => Process::result(''),
    ]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])->assertExitCode(0);

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain('my.app:')
        ->toContain('sail-proxy:');
});

it('writes the override and restarts the project', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])->assertExitCode(0);

    $override = file_get_contents($this->project.'/compose.override.yaml');

    expect($override)
        ->toContain('container_name: !reset null')
        ->toContain('ports: !reset []')
        ->toContain("        aliases:\n          - myapp.localhost")
        ->toContain("networks:\n  sail-proxy:\n    external: true")
        ->not->toContain('dns:')
        ->not->toContain('takeout');

    assertRanProcess('docker compose down');
    assertRanProcess('docker compose up -d');
});

it('attaches the app to the takeout network with --takeout', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--takeout' => true, '--host' => 'myapp.localhost', '--force' => true])
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain("      sail-proxy:\n        aliases:\n          - myapp.localhost\n      takeout: null")
        ->toContain("  takeout:\n    external: true");
});

it('refuses --takeout when the takeout network does not exist', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(),
        'docker network inspect takeout' => Process::result(exitCode: 1),
    ]);

    $this->artisan('config', ['--takeout' => true, '--force' => true])
        ->expectsOutputToContain("Network 'takeout' does not exist.")
        ->expectsOutputToContain('takeout enable mysql')
        ->assertExitCode(1);

    expect(file_exists($this->project.'/compose.override.yaml'))->toBeFalse();
});

it('never creates the takeout network itself', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--takeout' => true, '--host' => 'myapp.localhost', '--force' => true])
        ->assertExitCode(0);

    assertDidntRunProcess('docker network create*takeout*');
});

it('asks before overwriting an existing override', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/compose.override.yaml', 'keep me');

    fakeProcesses(project());

    $this->artisan('config')
        ->expectsConfirmation("'compose.override.yaml' already exists. Overwrite?", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/compose.override.yaml'))->toBe('keep me');
});

it('warns instead of registering when the proxy is not running', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])
        ->expectsOutputToContain("Proxy is not running. Run 'sail-proxy install' first")
        ->assertExitCode(0);

    assertDidntRunProcess('*kamal-proxy deploy*');
});

it('registers the app with the proxy when --host is given', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(networks: []),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format*' => Process::result('proj-laravel.test-1'),
    ]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy myapp --target proj-laravel.test-1:80 --deploy-timeout 120s --host myapp.localhost');
});

it('defaults the hostname to the project directory name', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(networks: []),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format*' => Process::result('app-1'),
    ]);

    $expected = basename($this->project).'.localhost';

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', $expected)
        ->assertExitCode(0);

    assertRanProcess("* --host {$expected}");
});

it('fails when the app container is not running', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(networks: []),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format*' => Process::result(''),
    ]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])
        ->expectsOutputToContain('Could not find running container for laravel.test.')
        ->assertExitCode(1);
});

it('reads the project without its own override file', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/compose.override.yaml', 'stale');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])->assertExitCode(0);

    // Without -f, compose would merge our previous override back in and the
    // networks it added would accumulate on every run.
    assertRanProcess('docker compose -f compose.yaml config --services');
    assertRanProcess('docker compose -f compose.yaml config --format json');
    assertDidntRunProcess('docker compose config*');
});

it('does not accumulate networks across runs', function () {
    touch($this->project.'/compose.yaml');

    // The project itself knows nothing of takeout; only a previous run added it.
    fakeProcesses([...project(networks: ['sail']), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'no')
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->not->toContain('takeout');
});

it('writes no alias when registration is declined', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'no')
        ->assertExitCode(0);

    // With no hostname to alias there is nothing the map form buys us, so the
    // networks stay a plain list.
    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain("    networks:\n      - sail-proxy")
        ->not->toContain('aliases');

    assertDidntRunProcess('*kamal-proxy deploy*');
});

it('reports a compose file it cannot read', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        'docker compose*config --services' => Process::result(
            output: '',
            errorOutput: 'service "laravel.test" refers to undefined network takeout',
            exitCode: 1,
        ),
    ]);

    $this->artisan('config')
        ->expectsOutputToContain('Could not read compose.yaml.')
        ->expectsOutputToContain('refers to undefined network takeout')
        ->assertExitCode(1);
});
