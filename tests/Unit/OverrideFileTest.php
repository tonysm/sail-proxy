<?php

use App\Support\OverrideFile;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

it('renders an override for every service, wiring only the app service', function () {
    $yaml = (new OverrideFile('172.42.255.253'))->render(
        services: ['laravel.test', 'redis'],
        appService: 'laravel.test',
        appNetworks: ['sail-proxy'],
        externalNetworks: ['sail-proxy'],
    );

    expect($yaml)->toBe(<<<'YAML'
    services:
      laravel.test:
        container_name: !reset null
        network_mode: !reset null
        dns:
          - 172.42.255.253
        ports: !reset []
        networks:
          - sail-proxy
      redis:
        container_name: !reset null
        network_mode: !reset null
        ports: !reset []
    networks:
      sail-proxy:
        external: true

    YAML);
});

it('attaches the app to the takeout network and declares it external', function () {
    $yaml = (new OverrideFile('172.42.255.253'))->render(
        services: ['laravel.test'],
        appService: 'laravel.test',
        appNetworks: ['sail-proxy', 'takeout'],
        externalNetworks: ['sail-proxy', 'takeout'],
    );

    expect($yaml)->toBe(<<<'YAML'
    services:
      laravel.test:
        container_name: !reset null
        network_mode: !reset null
        dns:
          - 172.42.255.253
        ports: !reset []
        networks:
          - sail-proxy
          - takeout
    networks:
      sail-proxy:
        external: true
      takeout:
        external: true

    YAML);
});

it('preserves the networks a service is already attached to', function () {
    $yaml = (new OverrideFile('172.42.255.253'))->render(
        services: ['laravel.test'],
        appService: 'laravel.test',
        appNetworks: ['default', 'sail-proxy'],
        externalNetworks: ['sail-proxy'],
    );

    expect($yaml)
        ->toContain("      - default\n      - sail-proxy")
        ->not->toContain("  default:\n    external: true");
});

it('quotes service names that yaml would otherwise mangle', function () {
    $yaml = (new OverrideFile('172.42.255.253'))->render(
        services: ['yes', 'no:thing'],
        appService: 'yes',
        appNetworks: ['sail-proxy'],
        externalNetworks: ['sail-proxy'],
    );

    // A bare "yes" would parse back as a boolean, and "no:thing" as a mapping.
    $parsed = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);

    expect(array_keys($parsed['services']))->toBe(['yes', 'no:thing']);
});

it('emits reset tags that docker compose understands', function () {
    $yaml = (new OverrideFile('172.42.255.253'))->render(
        services: ['laravel.test'],
        appService: 'laravel.test',
        appNetworks: ['sail-proxy'],
        externalNetworks: ['sail-proxy'],
    );

    $parsed = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
    $service = $parsed['services']['laravel.test'];

    expect($service['container_name'])->toBeInstanceOf(TaggedValue::class)
        ->and($service['container_name']->getTag())->toBe('reset')
        ->and($service['container_name']->getValue())->toBeNull()
        ->and($service['ports']->getTag())->toBe('reset')
        ->and($service['ports']->getValue())->toBe([]);
});
