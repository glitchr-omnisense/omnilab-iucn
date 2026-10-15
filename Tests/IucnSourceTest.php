<?php

namespace Omnilab\Iucn\Tests;

use Omnilab\Exception\InvalidConfigException;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Iucn\IucnSource;
use Omnilab\Iucn\IucnSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * No token was used: the answers are those the IUCN's API v4 gave the R
 * client rredlist on 2024-09-18, from its public test fixtures
 * (Tests/Constructed, whose README says where each comes from).
 */
final class IucnSourceTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    /** @var list<array<string, list<string>>> */
    private array $headers = [];

    private function source(array $options = []): IucnSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $o): MockResponse {
            $this->urls[] = rawurldecode($url);
            $this->headers[] = $o['normalized_headers'] ?? [];
            $file = match (true) {
                str_ends_with($url, '/taxa/sis/9404') => 'taxa-sis-9404.json',
                str_contains($url, '/taxa/scientific_name?genus_name=Gorilla&species_name=gorilla') => 'taxa-scientific-name-gorilla-gorilla.json',
                str_ends_with($url, '/assessment/166290968') => 'assessment-166290968.json',
                default => null,
            };

            return null === $file ? new MockResponse('{"error":"Not found"}', ['http_code' => 404]) : new MockResponse((string) file_get_contents(__DIR__.'/Constructed/'.$file));
        });
        $source = (new IucnSourceFactory($http))->create($options + ['key' => 'test-token', 'throttle' => 0.0]);
        self::assertInstanceOf(IucnSource::class, $source);

        return $source;
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY], $this->source()->capabilities());
        self::assertSame(0.5, IucnSource::THROTTLE, 'the IUCN\'s half second');
    }

    public function testTheGorillaByItsSisIdThenItsLatestGlobalAssessment(): void
    {
        $gorilla = $this->source()->entity('9404');

        self::assertSame(['https://api.iucnredlist.org/api/v4/taxa/sis/9404', 'https://api.iucnredlist.org/api/v4/assessment/136250858'], $this->urls, 'the latest global assessment of the twelve');
        self::assertSame(['test-token'], array_map(static fn (string $h) => substr($h, \strlen('Authorization: ')), $this->headers[0]['authorization']));
        self::assertSame(['iucn:9404', 'Gorilla gorilla', Domain::TAXONOMY, 'species'], [(string) $gorilla->id, $gorilla->label, $gorilla->domain, $gorilla->kind]);
        self::assertSame(['Animalia', 'Chordata', 'Mammalia', 'Primates', 'Hominidae', 'Gorilla'], [$gorilla->value('kingdom'), $gorilla->value('phylum'), $gorilla->value('class'), $gorilla->value('order'), $gorilla->value('family'), $gorilla->value('genus')]);
        self::assertSame(['(Savage, 1847)', 12, 'IUCN SSC Primate Specialist Group', 'Global'], [$gorilla->value('taxon_authority'), $gorilla->value('assessment_count'), $gorilla->value('assessed_by'), $gorilla->value('assessment_scope')]);
        self::assertSame('Western Gorilla', $gorilla->value('common_name'));
        self::assertNull($gorilla->value('iucn_status'), 'the assessment unknown here (404): the taxon alone');
        self::assertSame('https://www.iucnredlist.org/species/9404/136250858', $gorilla->url);
        self::assertContains('iucn:39998', $gorilla->related('child')->keys(), 'Gorilla gorilla diehli');
    }

    public function testByItsName(): void
    {
        $gorilla = $this->source(['assessment' => false])->entity('Gorilla gorilla');

        self::assertSame(['https://api.iucnredlist.org/api/v4/taxa/scientific_name?genus_name=Gorilla&species_name=gorilla'], $this->urls);
        self::assertSame('iucn:9404', (string) $gorilla->id);
        self::assertNull($this->source()->entity('Gorilla'), 'one word is no species: no call');
    }

    public function testAnAssessment(): void
    {
        $puffin = $this->source()->assessment(166290968);

        self::assertSame(['iucn:22694927', 'Fratercula arctica', 'Atlantic Puffin'], [(string) $puffin->id, $puffin->label, $puffin->value('common_name')]);
        self::assertSame(['Endangered (EN)', 'A2abcde+4abcde', 'Decreasing', '2021', '2020-12-18', false], [$puffin->value('iucn_status'), $puffin->value('red_list_criteria'), $puffin->value('population_trend'), $puffin->value('assessment_year'), $puffin->value('assessment_date'), $puffin->value('possibly_extinct')]);
        self::assertSame(['7400000-8240000,7820000', '17'], [$puffin->value('population_size'), $puffin->value('generation_length')]);
        self::assertStringStartsWith('BirdLife International 2021. Fratercula arctica (Europe assessment).', $puffin->value('citation'));
        self::assertSame('https://www.iucnredlist.org/species/22694927/166290968', $puffin->url);
        self::assertSame('iucn', $puffin->property('iucn_status')->source);
    }

    public function testWithoutATokenEveryCallIsRefusedBeforeItIsMade(): void
    {
        $source = $this->source(['key' => null]);
        foreach ([static fn () => $source->entity('9404'), static fn () => $source->entity('Gorilla gorilla'), static fn () => $source->assessment(166290968)] as $call) {
            try {
                $call();
                self::fail('not refused');
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString('api.iucnredlist.org', $e->getMessage());
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testOtherSchemesAreRefused(): void
    {
        foreach ([Identifier::of(Scheme::WORMS, '137094'), 'gbif:4CGXP', 'Q140'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }
}
