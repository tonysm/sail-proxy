# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## What this is

A Laravel Zero CLI, distributed as a PHAR, that puts local Laravel Sail apps behind
[kamal-proxy](https://github.com/basecamp/kamal-proxy) so each one is served on its own
`*.localhost` hostname. `README.md` documents the user-facing commands and every
`SAIL_PROXY_*` environment variable; `CONTRIBUTING.md` documents the development
workflow in depth and is worth reading before changing anything non-trivial.

## Commands

```bash
composer install         # every runtime dep is in require-dev; --no-dev cannot boot
php sail-proxy <command> # run the app from source
vendor/bin/pest          # tests
vendor/bin/pest tests/Feature/ConfigCommandTest.php          # one file
vendor/bin/pest --filter='part of the test name'             # one test
vendor/bin/pint          # format
vendor/bin/pint --test   # check formatting (CI runs this)
```

CI runs Pest on PHP 8.3, 8.4 and 8.5.

## Architecture

Five commands in `app/Commands/`, each driving thin wrappers in `app/Support/`:

- `Docker` — the `docker` CLI (containers, networks, volumes, labels).
- `DockerCompose` — `docker compose` for a single project directory, bound as a singleton
  to `getcwd()`. Prefers `./vendor/bin/sail` over bare `docker compose` when present.
- `KamalProxy` — `kamal-proxy deploy|remove|list`, run via `docker exec` into the proxy
  container.
- `OverrideFile` — renders the `compose.override.yaml` that `config` writes.

`install` creates the shared network and starts the `sail-proxy` (kamal-proxy) container;
nothing is pinned, so Docker assigns the network a subnet and the proxy an address from its
own pool. `config` rewrites a project's compose to join that network — under a network alias
matching its hostname — and registers it; `register`/`unregister` talk to kamal-proxy
directly; `uninstall` reverses `install`.

Container-to-container resolution is Docker's embedded resolver answering that alias; there
is no DNS container. Host-to-container resolution is the OS sending `*.localhost` to
`127.0.0.1`, where the proxy publishes port 80.

Everything configurable lives in `config/proxy.php`, each key backed by a `SAIL_PROXY_*`
env var read from the real environment (not a `.env`).

## Invariants

- **Always pass argv to `Process` as an array**, never a string, so container names and
  hostnames can't shell-inject.
- **The shared network must not be named `sail`** — Sail's own compose file defines a
  project-local network by that name, and overriding it drags mysql/redis/mailpit onto the
  shared network where projects collide on aliases.
- **`config` inspects the project with `-f <base compose file>`**, never the merged config,
  or a re-run feeds our previous override back in and accumulates networks.
- **Restart projects through `./vendor/bin/sail`** (`DockerCompose::command()`): it exports
  `WWWUSER`/`WWWGROUP`, without which the container runs as the wrong UID.
- **`docker rm -f` exits 0 for a missing container**, so check existence separately before
  reporting a removal.
- **`config` has to settle the hostname before it writes the override**, because the
  hostname goes in as a network alias. Prompting for it at registration time — after the
  file is written — would be too late.
- **Aliases force the map form of `networks:`.** A plain list cannot carry them, so
  `OverrideFile` switches to `network: {aliases: [...]}` and has to bring the service's
  other networks along as keys with a null value. It keeps the list form when there is no
  alias to add.

## Testing

Tests fake the process layer; nothing touches Docker. Use the helpers in `tests/Pest.php`
rather than `Process::fake()` directly — `Process::fake()`'s string patterns don't match our
argv arrays:

```php
fakeProcesses(['docker ps*' => Process::result('sail-proxy')]);
assertRanProcess('docker exec sail-proxy kamal-proxy remove myapp');
assertDidntRunProcess('docker network create*');
```

`Process::recorded()` does not exist in this version of `illuminate/process`; to assert
ordering, record commands inside a `Process::fake(closure)` (see `UninstallCommandTest`).
Two `expectsOutputToContain()` calls matching the same output line will fail — assert the
whole line as one substring.

## Distribution

Composer serves the committed `builds/sail-proxy` archive (`bin` points at it, and `/builds`
is deliberately not gitignored), so it must be rebuilt and committed *before* tagging:

```bash
php sail-proxy app:build sail-proxy --build-version=v1.2.3
git add builds/sail-proxy && git commit -m "Build v1.2.3"
git tag -a v1.2.3 -m "v1.2.3" && git push --follow-tags
```

The release workflow fails if the version baked into the archive doesn't match the tag.
