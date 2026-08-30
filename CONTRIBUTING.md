# Contributing

## Setup

```bash
git clone https://github.com/tonysm/sail-proxy
cd sail-proxy
composer install
```

Run the app from source with `php sail-proxy <command>`. Note that every runtime dependency lives in `require-dev` (see [Distribution](#distribution)), so `composer install --no-dev` produces an app that cannot boot.

```bash
vendor/bin/pest          # tests
vendor/bin/pint          # formatting
vendor/bin/pint --test   # check without writing
```

Both run in CI against PHP 8.3, 8.4 and 8.5.

## Layout

| Path | |
|---|---|
| `app/Commands/` | one class per command: `install`, `uninstall`, `config`, `register`, `unregister` |
| `app/Support/Docker.php` | wrapper over the `docker` CLI |
| `app/Support/DockerCompose.php` | wrapper over `docker compose`, preferring `./vendor/bin/sail` |
| `app/Support/KamalProxy.php` | wrapper over `kamal-proxy`, run via `docker exec` |
| `app/Support/OverrideFile.php` | renders `compose.override.yaml` |
| `config/proxy.php` | every default, each overridable by a `SAIL_PROXY_*` environment variable |

Commands shell out through the `Process` facade. **Always pass argv as an array**, never a string, so container names and hostnames cannot shell-inject.

## Testing

Tests fake the process layer rather than touching Docker. `tests/Pest.php` provides the helpers, and you should use them instead of `Process::fake()` directly:

```php
fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);
assertRanProcess('docker exec sail-proxy kamal-proxy remove myapp');
assertDidntRunProcess('docker network create*');
```

Three things that will cost you an hour if you don't know them:

- **`Process::fake()` cannot pattern-match our commands.** Its string patterns only apply to string commands, and ours are argv arrays — they fall through to the default. `fakeProcesses()` exists to match on the joined argv instead.
- **`Process::recorded()` does not exist** in this version of `illuminate/process`. To assert ordering, record commands yourself inside a `Process::fake(closure)`; `UninstallCommandTest` does this to prove disconnects happen before the network is removed.
- **Two `expectsOutputToContain()` calls matching the same output line will fail.** It is backed by Mockery expectations on `doWrite`, and Mockery matches each call against only the first matching expectation, so the second substring is never cleared. Assert the whole line as one substring.

## Things that bit us

Worth knowing before changing the related code:

- **The shared network must not be called `sail`.** Sail's own `compose.yaml` defines a project-local network by that name. Declaring `sail: external: true` in the override replaces it and drags every service — mailpit, redis, mysql — onto the shared network, where two projects' `mysql` services collide on the same alias.
- **`config` reads the project with `-f <base compose file>`**, never the merged config. Reading the merged config feeds our own previous override back in, so each run re-adds the networks the last one wrote and eventually emits a file referencing an undeclared network.
- **Restart projects with `./vendor/bin/sail`, not bare `docker compose`.** Sail's script exports `WWWUSER`/`WWWGROUP`; without them the container runs as the wrong UID and the app fails on a readonly SQLite file. `DockerCompose::command()` handles this.
- **`docker rm -f` exits 0 for a container that does not exist**, so existence has to be checked separately before reporting that something was removed.
- **The compose `!reset` tags are emitted as `TaggedValue`.** `!reset null` is the documented Compose form; an empty sequence needs `Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE` or it dumps as `{}`, an empty map. `OverrideFileTest` pins the output.

## Distribution

The application is distributed as a PHAR, following Laravel Zero's [Packagist guidance](https://laravel-zero.com/docs/distribute-as-a-phar-archive). Two consequences:

- Everything bundled into the archive lives in `require-dev`, leaving only `php` in `require`. Composer then installs no dependencies at all, so a global install cannot conflict with other tools.
- `composer.json` points `bin` at `builds/sail-proxy`, so **the built archive is committed** and `/builds` is deliberately absent from `.gitignore`.

## Releasing

The archive is what Composer serves, so it has to be rebuilt and committed *before* the tag:

```bash
php sail-proxy app:build sail-proxy --build-version=v1.2.3
git add builds/sail-proxy && git commit -m "Build v1.2.3"
git tag -a v1.2.3 -m "v1.2.3" && git push --follow-tags
```

Pushing the tag triggers `.github/workflows/release.yml`, which attaches the archive to the GitHub release. It first checks that the version baked into `builds/sail-proxy` matches the tag and fails the release if not, so forgetting the rebuild is caught rather than silently shipped.
