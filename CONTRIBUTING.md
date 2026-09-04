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
- **The app service's `dns:` entry never replaced Docker's resolver.** On a user-defined
  network `/etc/resolv.conf` always points at `127.0.0.11`, and a `dns:` value becomes that
  resolver's *upstream* — visible as `# ExtServers: [...]` in the file. This is why dropping
  it in favour of network aliases cost no service discovery, and why a dead address there
  used to break every external lookup in the container.
- **`.localhost` is not special-cased in the *resolver*, but it is in libcurl.** glibc's
  `files dns` sends `foo.localhost` to the resolver rather than synthesising loopback, and
  Docker's embedded resolver answers a `.localhost` alias from its own table before
  forwarding upstream. Both verified directly; re-check by attaching two aliases to one
  container and comparing which IP comes back, not by stopping the resolver (a dead upstream
  hangs the AAAA lookup and times out the whole query).

  **libcurl is the exception, and it is the one that matters.** It resolves `*.localhost` to
  `127.0.0.1` on its own, above the resolver, so nothing you put in DNS or `/etc/hosts`
  reaches it -- `extra_hosts` loses too. Inside a container that lands on the container's own
  web server. This is why every app gets a second alias on `proxy.container_tld`.

  It is not a regression we introduced: it arrived with Ubuntu 24.04. `sail-8.2`
  (22.04, curl 7.81) reaches the proxy and returns 200; `sail-8.1`, `-8.4` and `-8.5`
  (24.04, curl 8.5) all loop back. Note it tracks the base image, not the PHP version.
  `CURLOPT_RESOLVE` does override it, but only per-request from inside app code.
- **The compose `!reset` tags are emitted as `TaggedValue`.** `!reset null` is the documented Compose form; an empty sequence needs `Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE` or it dumps as `{}`, an empty map. `OverrideFileTest` pins the output.

## Distribution

The application is distributed as a PHAR, following Laravel Zero's [Packagist guidance](https://laravel-zero.com/docs/distribute-as-a-phar-archive). Two consequences:

- Everything bundled into the archive lives in `require-dev`, leaving only `php` in `require`. Composer then installs no dependencies at all, so a global install cannot conflict with other tools.
- `composer.json` points `bin` at `builds/sail-proxy`, so **the built archive is committed** and `/builds` is deliberately absent from `.gitignore`.

## Releasing

One command does the whole thing:

```bash
scripts/release.sh 1.2.3            # stable release, tag 1.2.3
scripts/release.sh 1.2.3-rc.1       # prerelease, tag 1.2.3-rc.1
scripts/release.sh 1.2.3 --dry-run  # validate and run the checks, change nothing
```

`VERSION` is bare semver (`1.2.3`), and the tag is the same string — no `v` prefix, which is what the release workflow's tag filter matches. The script:

1. Validates the version, that you are on the default branch with a clean tree synced to origin, and that the tag does not already exist — and refuses a stable version that is not newer than the latest stable tag, so a rejected release leaves main untouched.
2. Runs the checks (`pint --test`, `pest`).
3. Rebuilds the archive with the tag baked in (`app:build --build-version=`), verifies `--version` reports it, and commits `builds/sail-proxy`. This commit is what Composer serves, so it has to land *before* the tag.
4. Creates the annotated tag and pushes the commit and tag in one `--atomic` push. A rejection — the tag got taken or main moved while the build ran — rolls the local clone back to where it started.

Pushing the tag triggers `.github/workflows/release.yml`, which runs against the tag SHA. It re-runs the checks, verifies the version baked into `builds/sail-proxy` matches the tag (so a hand-pushed tag with a stale archive fails instead of shipping), then publishes the GitHub release with the archive, a `checksums.txt`, and a build-provenance attestation. Tags containing `-` are marked prerelease and never become Latest, which also keeps them out of mise's `latest` resolution.

The same archive asset is what `mise use -g github:tonysm/sail-proxy` and the curl one-liner install, so the asset name is load-bearing: keep it bare and unversioned (`sail-proxy`), or both break.
