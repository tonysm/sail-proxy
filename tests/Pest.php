<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fake every process, resolving results from a map of argv patterns.
 *
 * Our commands build argv as arrays so that names can't shell-inject, and
 * Process::fake() only pattern-matches string commands, so we match against
 * the joined argv ourselves.
 *
 * @param  array<string, mixed>  $map
 */
function fakeProcesses(array $map = []): void
{
    Process::fake(function ($process) use ($map) {
        $command = processCommand($process);

        foreach ($map as $pattern => $result) {
            if (Str::is($pattern, $command)) {
                return value($result);
            }
        }

        return Process::result();
    });
}

/**
 * Assert a process matching the given argv pattern ran.
 */
function assertRanProcess(string $pattern): void
{
    Process::assertRan(
        fn ($process): bool => Str::is($pattern, processCommand($process)),
    );
}

/**
 * Assert no process matching the given argv pattern ran.
 */
function assertDidntRunProcess(string $pattern): void
{
    Process::assertDidntRun(
        fn ($process): bool => Str::is($pattern, processCommand($process)),
    );
}

/**
 * The argv of a process, joined for pattern matching.
 */
function processCommand(object $process): string
{
    return implode(' ', (array) $process->command);
}
