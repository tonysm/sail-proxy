<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docker Network
    |--------------------------------------------------------------------------
    |
    | The dedicated network that the proxy, the DNS resolver and every proxied
    | app container share. The subnet is fixed so that the proxy and the DNS
    | resolver can be pinned to stable addresses the containers can reach.
    |
    | Note this must not be called "sail": Sail's own compose file defines a
    | project-local network by that name, and reusing it in the override would
    | replace that definition and drag every service onto the shared network.
    |
    | Takeout's own network takes whatever subnet Docker assigns it, so the
    | two coexist. An older "takeout" network pinned to this subnet will
    | conflict; remove it.
    |
    */

    'network' => [
        'name' => env('SAIL_PROXY_NETWORK', 'sail-proxy'),
        'subnet' => env('SAIL_PROXY_SUBNET', '172.42.0.0/16'),
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
        'ip' => env('SAIL_PROXY_IP', '172.42.255.254'),
        'image' => env('SAIL_PROXY_IMAGE', 'basecamp/kamal-proxy:once-01'),
        'metrics_port' => env('SAIL_PROXY_METRICS_PORT', 9000),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS Container
    |--------------------------------------------------------------------------
    |
    | dnsmasq resolves every "*.localhost" hostname to the proxy and forwards
    | everything else upstream.
    |
    */

    'dns' => [
        'name' => env('SAIL_PROXY_DNS_NAME', 'sail-dns'),
        'ip' => env('SAIL_PROXY_DNS_IP', '172.42.255.253'),
        'image' => env('SAIL_PROXY_DNS_IMAGE', 'drpsychick/dnsmasq'),
        'upstream' => env('SAIL_PROXY_DNS_UPSTREAM', '8.8.8.8'),
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

];
