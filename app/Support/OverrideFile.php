<?php

namespace App\Support;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class OverrideFile
{
    public function __construct(protected string $dnsIp)
    {
        //
    }

    /**
     * Render the compose override that puts the app behind the proxy.
     *
     * Every service is stripped of the settings Sail hardcodes and that would
     * otherwise conflict with the proxy: fixed container names, host port
     * bindings, and the host network mode. The app service is additionally
     * attached to the proxy network and pointed at our DNS resolver.
     *
     * @param  array<int, string>  $services  every service in the project
     * @param  array<int, string>  $appNetworks  the full network list for the app service
     * @param  array<int, string>  $externalNetworks  networks we declare as external
     */
    public function render(
        array $services,
        string $appService,
        array $appNetworks,
        array $externalNetworks,
    ): string {
        $definitions = [];

        foreach ($services as $service) {
            $definition = [
                'container_name' => new TaggedValue('reset', null),
                'network_mode' => new TaggedValue('reset', null),
            ];

            if ($service === $appService) {
                $definition['dns'] = [$this->dnsIp];
            }

            $definition['ports'] = new TaggedValue('reset', []);

            if ($service === $appService) {
                $definition['networks'] = array_values($appNetworks);
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
}
