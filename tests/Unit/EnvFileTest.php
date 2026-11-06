<?php

use App\Support\EnvFile;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'sail-proxy-env-');
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

/**
 * The lines the file would change to, as they would read afterwards.
 *
 * @return array<int, string>
 */
function rewrites(string $path, string $contents, string $service, string $hostname = 'mail.blog.internal'): array
{
    file_put_contents($path, $contents);

    return array_column((new EnvFile($path))->references($service, $hostname), 'to');
}

it('finds a _HOST key set to the service name', function () {
    expect(rewrites($this->path, "MAIL_HOST=mailpit\nMAIL_PORT=1025\n", 'mailpit'))
        ->toBe(['MAIL_HOST=mail.blog.internal']);
});

it('swaps only the host in a url', function (string $line, string $expected) {
    expect(rewrites($this->path, $line."\n", 'minio', 'minio.blog.internal'))->toBe([$expected]);
})->with([
    'scheme and port' => ['AWS_ENDPOINT=http://minio:9000', 'AWS_ENDPOINT=http://minio.blog.internal:9000'],
    'path' => ['AWS_URL=http://minio:9000/local/x', 'AWS_URL=http://minio.blog.internal:9000/local/x'],
    'no port' => ['STORAGE_URL=https://minio', 'STORAGE_URL=https://minio.blog.internal'],
    'credentials' => ['S3_DSN=s3://user:pw@minio:9000/b', 'S3_DSN=s3://user:pw@minio.blog.internal:9000/b'],
]);

it('keeps quotes and trailing comments', function (string $line, string $expected) {
    expect(rewrites($this->path, $line."\n", 'mailpit'))->toBe([$expected]);
})->with([
    'double' => ['MAIL_HOST="mailpit"', 'MAIL_HOST="mail.blog.internal"'],
    'single' => ["MAIL_HOST='mailpit'", "MAIL_HOST='mail.blog.internal'"],
    'comment' => ['MAIL_HOST=mailpit # dev only', 'MAIL_HOST=mail.blog.internal # dev only'],
    'export' => ['export MAIL_HOST=mailpit', 'export MAIL_HOST=mail.blog.internal'],
]);

it('leaves anything that is not a host pointing at the service alone', function (string $line, string $service) {
    expect(rewrites($this->path, $line."\n", $service))->toBe([]);
})->with([
    'a comment' => ['# MAIL_HOST=mailpit', 'mailpit'],
    'a driver' => ['CACHE_STORE=redis', 'redis'],
    'a connection' => ['DB_CONNECTION=mysql', 'mysql'],
    'a customised value' => ['MAIL_HOST=smtp.example.com', 'mailpit'],
    'a value already updated' => ['MAIL_HOST=mail.blog.internal', 'mail'],
    'a longer host' => ['AWS_ENDPOINT=http://minio-two:9000', 'minio'],
    'a host that only starts with it' => ['AWS_ENDPOINT=http://minio.example.com', 'minio'],
    'the name in a url path' => ['DOCS_URL=https://example.com/minio', 'minio'],
    'a key that is not a host' => ['MAIL_FROM_NAME=mailpit', 'mailpit'],
]);

it('treats dots in a service name literally', function () {
    expect(rewrites($this->path, "APP_HOST=my.app\nOTHER_URL=http://myxapp\n", 'my.app'))
        ->toBe(['APP_HOST=mail.blog.internal']);
});

it('has no references when there is no file', function () {
    unlink($this->path);

    expect((new EnvFile($this->path))->references('mailpit', 'mail.blog.internal'))->toBe([]);
});

it('changes the referencing lines and nothing else', function () {
    $contents = "APP_NAME=\"My App\"\r\n\n# a comment\nMAIL_HOST=mailpit\r\nMAIL_PORT=1025\nLAST=no-newline";

    file_put_contents($this->path, $contents);

    $env = new EnvFile($this->path);

    $env->apply($env->references('mailpit', 'mail.blog.internal'));

    expect(file_get_contents($this->path))
        ->toBe("APP_NAME=\"My App\"\r\n\n# a comment\nMAIL_HOST=mail.blog.internal\r\nMAIL_PORT=1025\nLAST=no-newline");
});

it('finds nothing left to change once applied', function () {
    file_put_contents($this->path, "MAIL_HOST=mailpit\n");

    $env = new EnvFile($this->path);

    $env->apply($env->references('mailpit', 'mail.blog.internal'));

    expect($env->references('mailpit', 'mail.blog.internal'))->toBe([]);
});
