<?php

use Illuminate\Support\Facades\Process;

it('fails when the proxy is not running', function () {
    fakeProcesses(['docker ps*' => Process::result('')]);

    $this->artisan('unregister')
        ->expectsOutputToContain('Proxy is not running.')
        ->assertExitCode(1);
});

it('unregisters the given app', function () {
    fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);

    $this->artisan('unregister', ['app' => 'myapp'])->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy remove myapp');
});

it('lists registered apps to choose from, skipping the table header', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy list' => Process::result(
            "Service  Host            Target\nmyapp    myapp.localhost app-1:80\nblog     blog.localhost  blog-1:80",
        ),
    ]);

    $this->artisan('unregister')
        ->expectsChoice('Select an app to unregister:', 'blog', ['myapp', 'blog'])
        ->assertExitCode(0);

    assertRanProcess('docker exec sail-proxy kamal-proxy remove blog');
});

it('fails when no apps are registered', function () {
    fakeProcesses([
        'docker ps*' => Process::result('sail-proxy'),
        'docker exec sail-proxy kamal-proxy list' => Process::result('Service  Host  Target'),
    ]);

    $this->artisan('unregister')
        ->expectsOutputToContain('No apps registered with the proxy.')
        ->assertExitCode(1);
});
