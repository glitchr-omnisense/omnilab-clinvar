<?php

namespace Omnilab\ClinVar;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * ClinVar, through the NCBI's E-utilities - no key needed, an API key for
 * ten calls a second instead of three.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # E-utilities' "email" and the User-Agent: whom NCBI writes to before it blocks a caller
 *     api_key: '...'                   # an NCBI API key (ncbi.nlm.nih.gov, account settings); never written here
 *     tool: 'omnilab'                  # E-utilities' "tool": the name NCBI knows the application by
 *     throttle: auto                   # seconds between two calls; auto: 0.34 without a key, 0.1 with one
 *     base_uri: 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/'
 */
final class ClinVarSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'clinvar',
            'omnilab.required_options' => [],
            'api_key' => null,
            'tool' => 'omnilab',
            'throttle' => 'auto',
            'base_uri' => ClinVarSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('api_key');
        $throttle = $c->get('throttle');

        return new ClinVarSource(
            $this->http ?? HttpClient::create(),
            null === $key || '' === $key ? null : (string) $key,
            $c->get('mailto') ?: null,
            (string) $c->get('tool'),
            (string) $c->get('base_uri', ClinVarSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            'auto' === $throttle ? null : (float) $throttle,
        );
    }
}
