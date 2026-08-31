<?php

namespace App\Support;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class OverrideFile
{
    /**
     * Render the compose override that puts the app behind the proxy.
     *
     * Every service is stripped of the settings Sail hardcodes and that would
     * otherwise conflict with the proxy: fixed container names, host port
     * bindings, and the host network mode. The app service is additionally
     * attached to the proxy network, under an alias matching the hostname it
     * is served on so that containers on that network can reach it by name.
     *
     * @param  array<int, string>  $services  every service in the project
     * @param  array<int, string>  $appNetworks  the full network list for the app service
     * @param  array<int, string>  $externalNetworks  networks we declare as external
     * @param  array<string, array<int, string>>  $aliases  aliases to add, keyed by network
     */
    public function render(
        array $services,
        string $appService,
        array $appNetworks,
        array $externalNetworks,
        array $aliases = [],
    ): string {
        $definitions = [];

        foreach ($services as $service) {
            $definition = [
                'container_name' => new TaggedValue('reset', null),
                'network_mode' => new TaggedValue('reset', null),
                'ports' => new TaggedValue('reset', []),
            ];

            if ($service === $appService) {
                $definition['networks'] = $this->networks($appNetworks, $aliases);
            }

            $definitions[$service] = $definition;
        }

        $data = ['services' => $definitions];

        if ($externalNetworks !== []) {
            $data['networks'] = array_map(
                fn (): array => ['external' => true],
                array_flip(array_values($externalNetworks)),
            );
        }

        $yaml = Yaml::dump($data, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        // Purely cosmetic: the dumper breaks a tagged empty sequence onto its
        // own line. Both forms parse identically; this keeps the file tidy.
        return (string) preg_replace('/!reset\n\s+\[\]/', '!reset []', $yaml);
    }

    /**
     * The app service's networks.
     *
     * Compose accepts either a plain list of names or a map of name to
     * options, but aliases can only be expressed by the map form, so we stay
     * with the shorter list whenever there are none to add.
     *
     * @param  array<int, string>  $networks
     * @param  array<string, array<int, string>>  $aliases
     * @return array<int, string>|array<string, array<string, array<int, string>>|null>
     */
    protected function networks(array $networks, array $aliases): array
    {
        $networks = array_values($networks);

        if ($aliases === []) {
            return $networks;
        }

        $map = [];

        foreach ($networks as $network) {
            $map[$network] = isset($aliases[$network])
                ? ['aliases' => array_values($aliases[$network])]
                : null;
        }

        return $map;
    }
}
