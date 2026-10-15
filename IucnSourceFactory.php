<?php

namespace Omnilab\Iucn;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The IUCN Red List: a taxon by SIS id or name with its latest global
 * assessment, with the user's API token.
 *
 *   options:
 *     key: '%env(IUCN_API_KEY)%'      # the user's Red List API token (an account at api.iucnredlist.org); every call needs it
 *     assessment: true                # entity() reads the latest global assessment too (a second call)
 *     language: eng                   # the common name kept (ISO 639-3)
 *     throttle: 0.5                   # seconds between two calls: the IUCN asks for 0.5 at least
 *     mailto: 'support@glitchr.io'    # in the User-Agent
 *     base_uri: 'https://api.iucnredlist.org/api/v4/'
 */
final class IucnSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'iucn',
            'omnilab.required_options' => [],
            'key' => null,
            'assessment' => true,
            'language' => 'eng',
            'throttle' => IucnSource::THROTTLE,
            'base_uri' => IucnSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('key');

        return new IucnSource(
            $this->http ?? HttpClient::create(),
            null === $key || '' === $key ? null : (string) $key,
            (bool) $c->get('assessment'),
            (string) $c->get('language'),
            (string) $c->get('base_uri', IucnSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
