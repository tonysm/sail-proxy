# Sail Proxy

Serve your local [Laravel Sail](https://laravel.com/docs/sail) apps on local hostnames — `http://myapp.localhost` instead of `http://localhost:8080` — and run as many of them at once as you like side-by-side.

It runs one container: **`sail-proxy`** — [kamal-proxy](https://github.com/basecamp/kamal-proxy), which owns port 80 and routes each hostname to the right app container.

Your app containers join a shared `sail-proxy` network, drop their host port bindings, and get registered with the proxy by hostname.

Nothing has to resolve `*.localhost` for you: your OS already sends those names to `127.0.0.1`, where the proxy is listening.

## Container-to-container requests

Read this before you wire two apps together — it is the one thing about Sail Proxy that will surprise you.

**Every app answers to two hostnames.** An app served on `myapp.localhost` also gets `myapp.internal`:

| from | use |
|---|---|
| your browser, your shell, anything on the host | `http://myapp.localhost` |
| **inside a container**, calling another app | `http://myapp.internal` |

The reason is libcurl. It resolves anything ending in `.localhost` to `127.0.0.1` by itself, per [RFC 6761](https://www.rfc-editor.org/rfc/rfc6761), without ever asking a resolver. From your machine that is exactly right — `127.0.0.1:80` is the proxy. From inside a container it is not: `127.0.0.1:80` is *that container's own web server*, so the request loops back and you get the calling app's 404 instead of the app you asked for.

That covers `curl`, PHP's curl extension, Guzzle, and therefore Laravel's `Http` client. It cannot be fixed with DNS, `/etc/hosts`, or `extra_hosts` — curl never looks. The second hostname exists because a different suffix is the only thing that works.

So when one app calls another, pick the hostname based on where the code is running:

```php
// config/services.php
'blog' => [
    'url' => env('BLOG_URL', env('LARAVEL_SAIL', 0)
        ? 'http://blog.internal'
        : 'http://blog.localhost'),
],
```

Sail sets `LARAVEL_SAIL=1` inside the container, so this picks `.internal` for code running under Sail and `.localhost` when you run `artisan` from the host.

Use it for **server-side requests to another app**. Any URL you render into HTML for a browser stays `.localhost` — the browser is on the host, where `.localhost` is the name that works.

## Requirements

Just Docker.

## Install

```bash
composer global require tonysm/sail-proxy
```

Then `sail-proxy` is available everywhere.

That installs a single self-contained archive — no dependencies are added to your global Composer setup, so it can't conflict with anything else you have installed.

Or, if you'd rather not go through Composer, download the same binary from the [latest release](https://github.com/tonysm/sail-proxy/releases/latest):

```bash
curl -L https://github.com/tonysm/sail-proxy/releases/latest/download/sail-proxy -o ~/.local/bin/sail-proxy
chmod +x ~/.local/bin/sail-proxy
```

## Usage

Start the proxy once, then configure each app.

### `sail-proxy install`

Creates the `sail-proxy` network and starts the proxy. Run this once per machine; it is safe to re-run. Docker assigns the network a subnet from its own pool — nothing is pinned, so there is nothing to collide with a VPN route or another network.

### `sail-proxy start` / `stop` / `restart`

Take the proxy down and bring it back without losing anything:

```bash
sail-proxy stop      # frees port 80; your apps keep running
sail-proxy start
sail-proxy restart
```

These only ever touch the proxy container — app containers, the network and the volume of
registrations are left alone, so everything you had registered is still served when it comes
back. `sail-proxy status` tells you which state it is in.

A stopped proxy **stays stopped**, including across a reboot. The container runs with
`--restart unless-stopped`, and an explicit stop outranks that policy until something starts
it again. Use `start` for that; `install` won't help, since it leaves an existing container
where it is.

### `sail-proxy uninstall`

Removes everything `install` created: the `sail-proxy` container, the `sail-proxy` network, and the `sail-proxy` volume that holds the proxy's registrations. Asks first unless you pass `--force`.

Any app containers still attached to the network are disconnected so it can be removed. Project override files are left alone — the command lists the projects it found so you can delete their `compose.override.yaml` yourself, since a project that keeps one will fail to start once the network is gone.

### `sail-proxy config`

Run this from a Sail project directory. It writes a `compose.override.yaml` that:

- clears the fixed `container_name` on every service, so several projects can run side by side (we need this since they all join the same network);
- clears every host port binding, so nothing fights over port 80;
- puts the app service on the `sail-proxy` network, under network aliases matching the hostnames you serve it on.

It asks for that hostname up front, since it goes into the file, then restarts the project and registers it with the proxy.

The aliases are what let one project reach another by name: from inside a container, `http://blog.internal` resolves through Docker's own DNS straight to the container serving it. See [Container-to-container requests](#container-to-container-requests) for why that name ends in `.internal` and not `.localhost`.

| Option | |
|---|---|
| `--takeout` | also attach the app to [Takeout](https://github.com/tighten/takeout)'s network, so its MySQL/Redis/etc. containers are reachable by name |
| `--host=` | the hostname to serve on, skipping the prompt |
| `--force` | overwrite an existing `compose.override.yaml` without asking |

Give both `--host` and `--force` to run it unattended.

The app service is detected from `APP_SERVICE` in your `.env`, falling back to `laravel.test`.

### `sail-proxy register [container] [hostname] [port]`

Point a hostname at any running container, Sail or not. Prompts for anything you leave out.

Unlike `config`, this cannot add network aliases — it only points the proxy at a container that is already running, and never touches a compose file. Containers registered this way stay reachable from other containers by their container name only.

```bash
sail-proxy register my-container myapp.localhost 80
```

### `sail-proxy unregister [app]`

Stop serving an app. Prompts with the registered apps if you don't name one.

### `sail-proxy status`

Show whether the proxy is up, everything it is currently routing, and where each app runs
from on your machine.

```
  Proxy: on

 ┌──────────────────────┬────────────────────────────────┬────────────────────────────────────────┬─────────┬─────────────────────┐
 │ Service              │ Host                           │ Target                                 │ State   │ Directory           │
 ├──────────────────────┼────────────────────────────────┼────────────────────────────────────────┼─────────┼─────────────────────┤
 │ hotwire-starter-kit  │ hotwire-starter-kit.localhost  │ hotwire-starter-kit-laravel.test-1:80  │ running │ ~/Code/hotwire      │
 │ livewire-starter-kit │ livewire-starter-kit.localhost │ livewire-starter-kit-laravel.test-1:80 │ gone    │ container not found │
 └──────────────────────┴────────────────────────────────┴────────────────────────────────────────┴─────────┴─────────────────────┘
```

The directory comes from the labels Compose writes onto every container it creates, so it is
the project directory you ran `sail-proxy config` in. A container started outside Compose —
anything you pointed `register` at, say — carries no such label and shows `-`.

`gone` means the proxy is still routing a hostname to a container that no longer exists;
kamal-proxy keeps the registration and goes on reporting it as `running`. Run
`sail-proxy unregister` to clear it out.

Pass `--json` for the same thing as an object, including the two columns the table leaves
out (`path` and `tls`):

```json
{
    "active": true,
    "projects": [
        {
            "service": "myapp",
            "host": "myapp.localhost",
            "path": "/",
            "target": "myapp-laravel.test-1:80",
            "state": "running",
            "tls": "no",
            "container": "myapp-laravel.test-1",
            "exists": true,
            "project": "myapp",
            "dir": "/home/you/Code/myapp"
        }
    ]
}
```

`state` is what kamal-proxy believes; `exists` is whether the container is actually there.
A stopped proxy is a status, not a failure: you get `"active": false` with no projects, and
the command still exits 0. Branch on `active`, not on the exit code.

## Configuration

Every default can be overridden with an environment variable. These are read from the real environment, not from a `.env` file:

```bash
SAIL_PROXY_TLD=test sail-proxy register my-container myapp.test
```

| Variable | Default |
|---|---|
| `SAIL_PROXY_NETWORK` | `sail-proxy` |
| `SAIL_PROXY_NAME` | `sail-proxy` |
| `SAIL_PROXY_IMAGE` | `basecamp/kamal-proxy:once-01` |
| `SAIL_PROXY_METRICS_PORT` | `9000` |
| `SAIL_PROXY_TAKEOUT_NETWORK` | `takeout` |
| `SAIL_PROXY_DEPLOY_TIMEOUT` | `120s` |
| `SAIL_PROXY_OVERRIDE_FILE` | `compose.override.yaml` |
| `SAIL_PROXY_DEFAULT_SERVICE` | `laravel.test` |
| `SAIL_PROXY_TLD` | `localhost` |
| `SAIL_PROXY_CONTAINER_TLD` | `internal` |

Set them per-command as above, or export them from your shell profile to change the defaults for good. Note that the network name must not be `sail`: Sail's own compose file already defines a network by that name.

## If `*.localhost` doesn't resolve

Nothing in Sail Proxy resolves hostnames for your browser — your OS does, and most already send `*.localhost` to `127.0.0.1` (systemd-resolved on Linux does; Chrome and Firefox do it themselves on any platform).

If a name doesn't resolve — macOS Safari and `curl` are the likely cases — point the resolver at loopback yourself:

```bash
# macOS
echo 'nameserver 127.0.0.1' | sudo tee /etc/resolver/localhost
```

Check what your system currently does with `getent hosts myapp.localhost` (Linux) or `dscacheutil -q host -a name myapp.localhost` (macOS).

## Using it with Takeout

[Takeout](https://github.com/tighten/takeout) manages its own `takeout` Docker network. Sail Proxy never creates or modifies it — `--takeout` only attaches your app to it:

```bash
takeout enable mysql
sail-proxy config --takeout
```

Your app can then reach the Takeout containers by name (`mysql`, `redis`, …) while still being served on its `.localhost` hostname.
