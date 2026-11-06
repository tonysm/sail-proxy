<?php

use App\Support\DockerCompose;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

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
        ->toContain("        aliases:\n          - myapp.localhost\n          - myapp.internal")
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
        ->toContain("      sail-proxy:\n        aliases:\n          - myapp.localhost\n          - myapp.internal\n      takeout: null")
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
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
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

it('explains the container hostname after registering', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(networks: []),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format*' => Process::result('proj-laravel.test-1'),
    ]);

    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])
        ->expectsOutputToContain('From other containers, reach this app at http://myapp.internal')
        ->expectsOutputToContain('sail-proxy#container-to-container-requests')
        ->assertExitCode(0);
});

it('explains the container hostname even when the proxy is not running', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([...project(networks: []), 'docker ps*' => Process::result('')]);

    // The override is written either way, so the second name applies either way.
    $this->artisan('config', ['--host' => 'myapp.localhost', '--force' => true])
        ->expectsOutputToContain('From other containers, reach this app at http://myapp.internal')
        ->assertExitCode(0);
});

it('adds no second alias when the hostname is not a localhost one', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses([
        ...project(networks: []),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format*' => Process::result('proj-laravel.test-1'),
    ]);

    // libcurl only special-cases .localhost, so this hostname already works
    // from inside a container and needs no companion.
    $this->artisan('config', ['--host' => 'myapp.test', '--force' => true])
        ->doesntExpectOutputToContain('From other containers')
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain("        aliases:\n          - myapp.test\n")
        ->not->toContain('myapp.test.internal');
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

/**
 * A running project with mailpit, and the proxy up, faked to describe itself.
 */
function routableProject(array $overrides = []): array
{
    $config = ['services' => [
        'laravel.test' => [
            'networks' => ['sail' => null],
            'ports' => [['target' => 80, 'published' => '80'], ['target' => 5173, 'published' => '5173']],
        ],
        'mailpit' => [
            'networks' => ['sail' => null],
            'ports' => [['target' => 1025, 'published' => '1025'], ['target' => 8025, 'published' => '8025']],
        ],
        'mysql' => ['networks' => ['sail' => null]],
    ]];

    return [
        'docker compose*config --services' => Process::result("laravel.test\nmailpit\nmysql"),
        'docker compose*config --format json' => Process::result(json_encode($config)),
        'docker ps --format*' => Process::result('sail-proxy'),
        'docker compose ps --format {{.Names}} laravel.test' => Process::result('blog-laravel.test-1'),
        'docker compose ps --format {{.Names}} mailpit' => Process::result('blog-mailpit-1'),
        ...$overrides,
    ];
}

it('serves another port of the app service on its own hostname', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'laravel.test', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsQuestion('Port', '5173')
        ->expectsQuestion('Hostname', 'vite.blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->assertExitCode(0);

    // Vite isn't usually running yet, so the route is checked by the app's /up.
    assertRanProcess('docker exec sail-proxy kamal-proxy deploy vite.blog --target blog-laravel.test-1:5173 * --host vite.blog.localhost --health-check-port 80');
    assertRanProcess('docker exec sail-proxy kamal-proxy deploy blog --target blog-laravel.test-1:80 * --host blog.localhost');

    $override = Yaml::parseFile($this->project.'/compose.override.yaml', Yaml::PARSE_CUSTOM_TAGS);

    expect($override['services']['laravel.test']['networks']['sail-proxy']['aliases'])
        ->toBe(['blog.localhost', 'blog.internal', 'vite.blog.localhost', 'vite.blog.internal'])
        ->and($override['services']['mailpit'])->not->toHaveKey('networks');
});

it('reads the ports a service declares from inside the container', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $compose = app(DockerCompose::class);

    expect($compose->portsFor('mailpit'))->toBe(['1025', '8025'])
        ->and($compose->portsFor('mysql'))->toBe([]);
});

it('makes the user choose when a service declares several ports', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'mailpit', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsOutputToContain('mailpit declares ports 1025, 8025. Choose the one that serves HTTP.')
        ->expectsQuestion('Port', null)
        ->expectsOutputToContain('The port has to be a number.')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->assertExitCode(0);

    assertDidntRunProcess('*blog-mailpit-1*');
});

it('attaches another service to the proxy network and registers it', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'mailpit', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsQuestion('Port', '8025')
        ->expectsQuestion('Hostname', 'mail.blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy deploy mail.blog --target blog-mailpit-1:8025 * --host mail.blog.localhost --health-check-path /');

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain("      sail: null\n      sail-proxy:\n        aliases:\n          - mail.blog.localhost\n          - mail.blog.internal");
});

it('asks again when a hostname is already in use', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'mailpit', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsQuestion('Port', '8025')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsOutputToContain("Hostname 'blog.localhost' is already in use.")
        ->expectsQuestion('Hostname', 'mail.blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->assertExitCode(0);

    assertRanProcess('* --host mail.blog.localhost *');
});

it('updates .env when the user accepts', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_MAILER=smtp\nMAIL_HOST=mailpit\nMAIL_PORT=1025\n");

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'mailpit', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsQuestion('Port', '8025')
        ->expectsQuestion('Hostname', 'mail.blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->expectsOutputToContain('MAIL_HOST=mailpit  ->  MAIL_HOST=mail.blog.internal')
        ->expectsConfirmation('Update .env?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))
        ->toBe("MAIL_MAILER=smtp\nMAIL_HOST=mail.blog.internal\nMAIL_PORT=1025\n");
});

it('warns and leaves .env alone when the user declines', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true])
        ->expectsConfirmation('Register laravel.test with the proxy?', 'yes')
        ->expectsQuestion('Hostname', 'blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'yes')
        ->expectsChoice('Service', 'mailpit', ['laravel.test', 'mailpit', 'mysql'])
        ->expectsQuestion('Port', '8025')
        ->expectsQuestion('Hostname', 'mail.blog.localhost')
        ->expectsConfirmation('Serve another service or port on its own hostname?', 'no')
        ->expectsConfirmation('Update .env?', 'no')
        ->expectsOutputToContain("mailpit may resolve to another project's container")
        ->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mailpit\n");
});

it('names the hostname to use when .env does not mention the service', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true, '--host' => 'blog.localhost', '--route' => ['mailpit:8025:mail.blog.localhost']])
        ->expectsOutputToContain('If your app calls mailpit directly, use mail.blog.internal rather than mailpit.')
        ->assertExitCode(0);
});

it('takes routes from options without asking anything', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost', 'laravel.test:5173:vite.blog.localhost'],
    ])
        ->expectsOutputToContain("mailpit may resolve to another project's container")
        ->assertExitCode(0);

    assertRanProcess('* --target blog-mailpit-1:8025 *');
    assertRanProcess('* --target blog-laravel.test-1:5173 *');

    // Nothing asked for that file to change, and nobody was there to ask.
    expect(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mailpit\n");
});

it('updates .env from options with --update-env', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost'],
        '--update-env' => true,
    ])->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mail.blog.internal\n");
});

it('uses a hostname as its own container name when it is not a localhost one', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.test',
        '--route' => ['mailpit:8025:mail.blog.test'],
        '--update-env' => true,
    ])->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mail.blog.test\n");
});

it('points .env at one name for a service routed twice', function () {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost', 'mailpit:1025:smtp.blog.localhost'],
        '--update-env' => true,
    ])->assertExitCode(0);

    expect(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mail.blog.internal\n");

    expect(file_get_contents($this->project.'/compose.override.yaml'))
        ->toContain("          - mail.blog.internal\n          - smtp.blog.localhost\n          - smtp.blog.internal");
});

it('refuses an invalid route before writing anything', function (string $route, string $message) {
    touch($this->project.'/compose.yaml');
    file_put_contents($this->project.'/.env', "MAIL_HOST=mailpit\n");

    fakeProcesses(routableProject());

    $this->artisan('config', ['--force' => true, '--host' => 'blog.localhost', '--route' => [$route]])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect(file_exists($this->project.'/compose.override.yaml'))->toBeFalse()
        ->and(file_get_contents($this->project.'/.env'))->toBe("MAIL_HOST=mailpit\n");

    assertDidntRunProcess('docker compose down');
})->with([
    'malformed' => ['mailpit:8025', "Invalid route 'mailpit:8025'"],
    'port not a number' => ['mailpit:web:mail.blog.localhost', "Invalid route 'mailpit:web:mail.blog.localhost'"],
    'unknown service' => ['nope:8025:mail.blog.localhost', "Service 'nope' not found"],
    'hostname taken' => ['mailpit:8025:blog.localhost', "Hostname 'blog.localhost' is used more than once."],
]);

it('never asks about routes when --host is given alone', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject());

    // An unexpected question would fail this; the mock has no answer for it.
    $this->artisan('config', ['--force' => true, '--host' => 'blog.localhost'])->assertExitCode(0);

    assertDidntRunProcess('*--health-check*');
});

it('tries the remaining routes after one fails, and reports the failure', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject([
        '*kamal-proxy deploy mail.blog *' => Process::result(errorOutput: 'target failed to become healthy', exitCode: 1),
    ]));

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost', 'laravel.test:5173:vite.blog.localhost'],
    ])
        ->expectsOutputToContain('target failed to become healthy')
        ->expectsOutputToContain('sail-proxy register blog-mailpit-1 mail.blog.localhost 8025 --health-path=/some/path')
        ->assertExitCode(1);

    assertRanProcess('* --host vite.blog.localhost *');
});

it('does not register routes when the app itself failed to register', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject([
        '*kamal-proxy deploy blog *' => Process::result(errorOutput: 'target failed to become healthy', exitCode: 1),
    ]));

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost'],
    ])->assertExitCode(1);

    assertDidntRunProcess('*kamal-proxy deploy mail.blog *');
});

it('prints how to register routes when the proxy is not running', function () {
    touch($this->project.'/compose.yaml');

    fakeProcesses(routableProject(['docker ps --format*' => Process::result('')]));

    $this->artisan('config', [
        '--force' => true,
        '--host' => 'blog.localhost',
        '--route' => ['mailpit:8025:mail.blog.localhost', 'laravel.test:5173:vite.blog.localhost'],
    ])
        ->expectsOutputToContain('sail-proxy register blog-mailpit-1 mail.blog.localhost 8025 --health-path=/')
        ->expectsOutputToContain('sail-proxy register blog-laravel.test-1 vite.blog.localhost 5173 --health-port=80')
        ->assertExitCode(0);

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
