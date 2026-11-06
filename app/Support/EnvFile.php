<?php

namespace App\Support;

class EnvFile
{
    public function __construct(protected string $path)
    {
        //
    }

    /**
     * The lines pointing at a service by its short name, and what they would
     * become if they pointed at the given hostname instead.
     *
     * A line counts if it is a "*_HOST" key set to exactly the service name, or
     * a URL whose host is the service name. Matching on the value alone would
     * also catch settings that merely share the name, like CACHE_STORE=redis.
     *
     * @return array<int, array{line: int, from: string, to: string}>
     */
    public function references(string $service, string $hostname): array
    {
        $references = [];

        foreach ($this->lines() as $number => $line) {
            $text = rtrim($line, "\r\n");

            $rewritten = $this->rewrite($text, $service, $hostname);

            if ($rewritten !== null) {
                $references[] = ['line' => $number, 'from' => $text, 'to' => $rewritten];
            }
        }

        return $references;
    }

    /**
     * Apply references from references(), leaving every other line untouched.
     *
     * @param  array<int, array{line: int, from: string, to: string}>  $references
     */
    public function apply(array $references): void
    {
        $lines = $this->lines();

        foreach ($references as ['line' => $number, 'from' => $from, 'to' => $to]) {
            // Only the text is replaced, so the line keeps its own line ending.
            $lines[$number] = $to.substr($lines[$number], strlen($from));
        }

        file_put_contents($this->path, implode('', $lines));
    }

    /**
     * Each line of the file, with its line ending.
     *
     * @return array<int, string>
     */
    protected function lines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        return preg_split('/(?<=\n)/', (string) file_get_contents($this->path), flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The line with the service swapped for the hostname, or null if the line
     * doesn't refer to the service.
     */
    protected function rewrite(string $line, string $service, string $hostname): ?string
    {
        if (! preg_match('/^(\s*(?:export\s+)?([A-Za-z_]\w*)=)(["\']?)(.*?)\3(\s*(?:#.*)?)$/', $line, $matches)) {
            return null;
        }

        [, $assignment, $key, $quote, $value, $rest] = $matches;

        $value = $this->rewriteValue($key, $value, $service, $hostname);

        return $value === null ? null : $assignment.$quote.$value.$quote.$rest;
    }

    /**
     * Swap the host in a value, keeping any scheme, credentials, port and path.
     */
    protected function rewriteValue(string $key, string $value, string $service, string $hostname): ?string
    {
        if (str_ends_with($key, '_HOST') && $value === $service) {
            return $hostname;
        }

        $url = '~^([A-Za-z][A-Za-z0-9+.-]*://(?:[^/@]*@)?)'.preg_quote($service, '~').'(?=[:/?#]|$)~';

        $rewritten = preg_replace($url, '${1}'.$hostname, $value, count: $count);

        return $count > 0 ? $rewritten : null;
    }
}
