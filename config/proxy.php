<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docker Network
    |--------------------------------------------------------------------------
    |
    | The dedicated network that the proxy and every proxied app container
    | share. Docker assigns it a subnet from its own pool; we pin nothing, so
    | there is nothing here to collide with another network or a VPN route.
    |
    | Apps find each other over this network by hostname: "config" gives the
    | app service a network alias matching the hostname it is served on, and
    | Docker's embedded resolver answers for it.
    |
    | Note this must not be called "sail": Sail's own compose file defines a
    | project-local network by that name, and reusing it in the override would
    | replace that definition and drag every service onto the shared network.
    |
    */

    'network' => [
        'name' => env('SAIL_PROXY_NETWORK', 'sail-proxy'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy Container
    |--------------------------------------------------------------------------
    |
    | kamal-proxy terminates every request on port 80 and forwards it to the
    | container registered for the requested hostname.
    |
    */

    'proxy' => [
        'name' => env('SAIL_PROXY_NAME', 'sail-proxy'),
        'image' => env('SAIL_PROXY_IMAGE', 'basecamp/kamal-proxy:once-01'),
        'metrics_port' => env('SAIL_PROXY_METRICS_PORT', 9000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Takeout Network
    |--------------------------------------------------------------------------
    |
    | Tighten Takeout manages this network itself. We never create it: the
    | "config --takeout" option only attaches the app service to it so that
    | Takeout's service containers stay reachable by name.
    |
    */

    'takeout_network' => env('SAIL_PROXY_TAKEOUT_NETWORK', 'takeout'),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    'deploy_timeout' => env('SAIL_PROXY_DEPLOY_TIMEOUT', '120s'),

    'override_file' => env('SAIL_PROXY_OVERRIDE_FILE', 'compose.override.yaml'),

    'default_service' => env('SAIL_PROXY_DEFAULT_SERVICE', 'laravel.test'),

    'tld' => env('SAIL_PROXY_TLD', 'localhost'),

    /*
    | The suffix apps use to reach each other from inside a container.
    |
    | They need a second name because libcurl resolves anything ending in
    | ".localhost" to 127.0.0.1 itself, per RFC 6761, without ever asking a
    | resolver -- so a request to "myapp.localhost" from inside a container
    | loops back to the calling container instead of reaching the app. That
    | covers curl, Guzzle and Laravel's HTTP client. No DNS-side arrangement
    | can change it, so "config" writes a second alias on a suffix libcurl
    | leaves alone.
    */

    'container_tld' => env('SAIL_PROXY_CONTAINER_TLD', 'internal'),

];
