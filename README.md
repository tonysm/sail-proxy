# Sail Proxy

Serve your local [Laravel Sail](https://laravel.com/docs/sail) apps on local hostnames — `http://myapp.localhost` instead of `http://localhost:8080` — and run as many of them at once as you like side-by-side.

It wires up two containers:

- **`sail-proxy`** — [kamal-proxy](https://github.com/basecamp/kamal-proxy), which owns port 80 and routes each hostname to the right app container.
- **`sail-dns`** — dnsmasq, which resolves every `*.localhost` name to the proxy.

Your app containers join a shared `sail-proxy` network, drop their host port bindings, and get registered with the proxy by hostname.

## Requirements

Just Docker.

## Install

```bash
composer global require tonysm/sail-proxy
```

Then `sail-proxy` is available everywhere.

## Usage

Start the proxy once, then configure each app.

### `sail-proxy install`

Creates the `sail-proxy` network and starts the proxy and DNS containers. Run this once per machine; it is safe to re-run.

### `sail-proxy uninstall`

Removes everything `install` created: the `sail-proxy` and `sail-dns` containers, the `sail-proxy` network, and the `sail-proxy` volume that holds the proxy's registrations. Asks first unless you pass `--force`.

Any app containers still attached to the network are disconnected so it can be removed. Project override files are left alone — the command lists the projects it found so you can delete their `compose.override.yaml` yourself, since a project that keeps one will fail to start once the network is gone.

### `sail-proxy config`

Run this from a Sail project directory. It writes a `compose.override.yaml` that:

- clears the fixed `container_name` on every service, so several projects can run side by side (we need this since they all join the same network);
- clears every host port binding, so nothing fights over port 80;
- puts the app service on the `sail-proxy` network and points it at the DNS container.

It then restarts the project and offers to register it with the proxy.

| Option | |
|---|---|
| `--takeout` | also attach the app to [Takeout](https://github.com/tighten/takeout)'s network, so its MySQL/Redis/etc. containers are reachable by name |
| `--host=` | the hostname to serve on, skipping the prompt |
| `--force` | overwrite an existing `compose.override.yaml` without asking |

Give both `--host` and `--force` to run it unattended.

The app service is detected from `APP_SERVICE` in your `.env`, falling back to `laravel.test`.

### `sail-proxy register [container] [hostname] [port]`

Point a hostname at any running container, Sail or not. Prompts for anything you leave out.

```bash
sail-proxy register my-container myapp.localhost 80
```

### `sail-proxy unregister [app]`

Stop serving an app. Prompts with the registered apps if you don't name one.

## Configuration

Every default — network name, subnet, container names, images, the proxy IPs, the deploy timeout, the TLD — is set in `config/proxy.php` and overridable by environment variable. See that file for the full list.

## Using it with Takeout

[Takeout](https://github.com/tighten/takeout) manages its own `takeout` Docker network. Sail Proxy never creates or modifies it — `--takeout` only attaches your app to it:

```bash
takeout enable mysql
sail-proxy config --takeout
```

Your app can then reach the Takeout containers by name (`mysql`, `redis`, …) while still being served on its `.localhost` hostname.
