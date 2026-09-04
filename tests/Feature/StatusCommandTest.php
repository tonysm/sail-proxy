<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * kamal-proxy colours every cell of its table, terminal or not, so the fixture
 * carries the escape codes the parser has to strip.
 */
function kamalProxyList(): string
{
    return implode("\n", [
        "\e[3;94mService\e[0m  \e[3;94mHost\e[0m             \e[3;94mPath\e[0m  \e[3;94mTarget\e[0m                   \e[3;94mState\e[0m    \e[3;94mTLS\e[0m",
        "\e[1;34mmyapp\e[0m    \e[mmyapp.localhost\e[0m  \e[m/\e[0m     \e[mmyapp-laravel.test-1:80\e[0m  \e[mrunning\e[0m  \e[mno\e[0m",
        "\e[1;34mblog\e[0m     \e[mblog.localhost\e[0m   \e[m/\e[0m     \e[mblog-laravel.test-1:80\e[0m   \e[mrunning\e[0m  \e[mno\e[0m",
    ]);
}

/**
 * Run the command, returning its exit code and everything it printed.
 *
 * The table and the JSON each land as a single chunk of output, and
 * expectsOutputToContain() only ever matches one substring per chunk.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runStatus(array $parameters = []): array
{
    $code = Artisan::call('status', $parameters);

    return [$code, Artisan::output()];
}

/**
 * The proxy running, with myapp's container in place and blog's long gone.
 *
 * @param  array<string, string>  $labels  the compose labels docker reports for myapp
 */
function fakeProxyWithApps(array $labels = [
    'project' => 'myapp',
    'dir' => '/opt/projects/myapp',
]): void
{
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy list' => Process::result(kamalProxyList()),
        'docker inspect myapp-laravel.test-1*' => Process::result(
            $labels['project']."\n".$labels['dir'],
        ),
        'docker inspect blog-laravel.test-1*' => Process::result(
            output: '', errorOutput: 'No such object', exitCode: 1,
        ),
    ]);
}

it('reports the proxy as off without asking it for anything', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    [$code, $output] = runStatus();

    expect($code)->toBe(0)
        ->and($output)->toContain('Proxy: off')
        ->and($output)->toContain("Run 'sail-proxy install' to start it.");

    assertDidntRunProcess('docker exec * kamal-proxy list');
    assertDidntRunProcess('docker inspect*');
});

it('reports the proxy as off in JSON', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    [$code, $output] = runStatus(['--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe([
            'active' => false,
            'projects' => [],
        ]);
});

it('lists the registered apps in a table', function () {
    fakeProxyWithApps();

    [$code, $output] = runStatus();

    expect($code)->toBe(0)
        ->and($output)->toContain('Proxy: on')
        ->and($output)->toContain('myapp.localhost')
        ->and($output)->toContain('myapp-laravel.test-1:80')
        ->and($output)->toContain('running')
        ->and($output)->toContain('/opt/projects/myapp')
        ->and($output)->toContain('blog.localhost')
        ->and($output)->toContain('blog-laravel.test-1:80');
});

it('marks a registration whose container is gone', function () {
    fakeProxyWithApps();

    [, $output] = runStatus();

    expect($output)->toContain('gone')
        ->and($output)->toContain('container not found');
});

it('shows a directory under the home directory as a relative path', function () {
    fakeProxyWithApps(['project' => 'myapp', 'dir' => getenv('HOME').'/Code/myapp']);

    [, $output] = runStatus();

    expect($output)->toContain('~/Code/myapp');
});

it('has no directory to show for a container compose did not create', function () {
    // Both labels empty: the container is there, it just isn't a compose project.
    fakeProxyWithApps(['project' => '', 'dir' => '']);

    [, $json] = runStatus(['--json' => true]);
    [, $output] = runStatus();

    $project = json_decode($json, true)['projects'][0];

    expect($project['exists'])->toBeTrue()
        ->and($project['project'])->toBeNull()
        ->and($project['dir'])->toBeNull()
        ->and($output)->toContain('running');
});

it('outputs every column as JSON, with the container behind it', function () {
    fakeProxyWithApps();

    [$code, $output] = runStatus(['--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe([
            'active' => true,
            'projects' => [
                [
                    'service' => 'myapp',
                    'host' => 'myapp.localhost',
                    'path' => '/',
                    'target' => 'myapp-laravel.test-1:80',
                    'state' => 'running',
                    'tls' => 'no',
                    'container' => 'myapp-laravel.test-1',
                    'exists' => true,
                    'project' => 'myapp',
                    'dir' => '/opt/projects/myapp',
                ],
                [
                    'service' => 'blog',
                    'host' => 'blog.localhost',
                    'path' => '/',
                    'target' => 'blog-laravel.test-1:80',
                    'state' => 'running',
                    'tls' => 'no',
                    'container' => 'blog-laravel.test-1',
                    'exists' => false,
                    'project' => null,
                    'dir' => null,
                ],
            ],
        ]);
});

it('keeps labels in the order they were asked for when an earlier one is empty', function () {
    // An unset first label is a leading blank line in docker's output; read
    // positionally, or the directory lands in the project name.
    fakeProxyWithApps(['project' => '', 'dir' => '/opt/projects/myapp']);

    [, $output] = runStatus(['--json' => true]);

    $project = json_decode($output, true)['projects'][0];

    expect($project['project'])->toBeNull()
        ->and($project['dir'])->toBe('/opt/projects/myapp');
});

it('says so when nothing is registered', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy list' => Process::result('Service  Host  Path  Target  State  TLS'),
    ]);

    [$code, $output] = runStatus();

    expect($code)->toBe(0)
        ->and($output)->toContain('Proxy: on')
        ->and($output)->toContain('No apps registered with the proxy.');
});
