<?php

namespace Omnilab\Iucn;

use Omnilab\Exception\InvalidConfigException;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The IUCN Red List of Threatened Species, through its API v4
 * (api.iucnredlist.org/api/v4), with the user's token: a taxon by its SIS
 * id or its scientific name - its classification, authority, common names,
 * synonyms, the IUCN groups that assess it - and its latest global
 * assessment: its Red List category (written as GBIF and iNaturalist write
 * it, "Endangered (EN)"), criteria, population trend, year, whether it is
 * possibly extinct, and the citation the IUCN asks for. An assessment by its
 * id (assessment(), outside the contract).
 *
 * The token is the user's to obtain from the IUCN (an account at
 * api.iucnredlist.org, under the Red List's terms of use) and to pass as
 * the "key" option; it is sent in the Authorization header, as the IUCN's
 * own R client, rredlist, sends it. Without it every call is refused before
 * it is made. The IUCN asks for half a second at least between two calls.
 */
final class IucnSource extends HttpSource
{
    public const BASE_URI = 'https://api.iucnredlist.org/api/v4/';

    /** Seconds between two calls: the IUCN recommends 0.5 at least. */
    public const THROTTLE = 0.5;

    /** The scope of the global assessments (the IUCN's code "1"). */
    public const GLOBAL_SCOPE = '1';

    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $key = null,
        private readonly bool $assessment = true,
        private readonly string $language = 'eng',
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = self::THROTTLE,
    ) {
        parent::__construct($http, $baseUri, null === $key ? $headers : $headers + ['Authorization' => $key], $throttle);
    }

    public function getName(): string
    {
        return 'iucn';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY];
    }

    /**
     * A taxon by its SIS id (134817215, iucn:134817215, its page) or its
     * scientific name (genus and species, and a subspecies or variety
     * after them: Gorilla gorilla, Gorilla gorilla diehli), with its latest
     * global assessment read whole (a second call; the "assessment" option
     * leaves it out).
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = Scheme::IUCN === $id->scheme ? $id : throw NotSupportedException::identifier('iucn', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::IUCN, $text) ?? Identifier::parse($text);
            if (null !== $identifier && Scheme::IUCN !== $identifier->scheme) {
                throw NotSupportedException::identifier('iucn', $id);
            }
        }
        if (null === $identifier) {
            $words = preg_split('~\s+~', (string) preg_replace('~\s(?:ssp|subsp|var)\.\s~i', ' ', $text));
            if (\count($words) < 2 || \count($words) > 3) {
                return null;
            }
            $this->needKey();
            $data = $this->getJson('taxa/scientific_name', ['genus_name' => $words[0], 'species_name' => $words[1], 'infra_name' => $words[2] ?? null]);
        } else {
            $this->needKey();
            $data = $this->getJson('taxa/sis/'.$identifier->value);
        }
        if (!\is_array($data) || !\is_array($data['taxon'] ?? null) || !isset($data['taxon']['sis_id'])) {
            return null;
        }
        $latest = null;
        foreach ($data['assessments'] ?? [] as $a) {
            if (\is_array($a) && true === ($a['latest'] ?? false) && \in_array(self::GLOBAL_SCOPE, array_map(static fn (mixed $s) => (string) ($s['code'] ?? ''), (array) ($a['scopes'] ?? [])), true)) {
                $latest = $a;
            }
        }
        $assessment = $this->assessment && isset($latest['assessment_id']) ? $this->getJson('assessment/'.$latest['assessment_id']) : null;

        return $this->toEntity($data['taxon'], $assessment, $latest, \count(array_filter((array) ($data['assessments'] ?? []), 'is_array')));
    }

    /**
     * An assessment by its id, outside the contract: the taxon it assesses,
     * with what that assessment says (any scope: a regional one, an older
     * one); null when unknown.
     */
    public function assessment(int|string $id): ?Entity
    {
        $this->needKey();
        $assessment = $this->getJson('assessment/'.rawurlencode((string) $id));

        return \is_array($assessment) && \is_array($assessment['taxon'] ?? null) && isset($assessment['taxon']['sis_id']) ? $this->toEntity($assessment['taxon'], $assessment) : null;
    }

    private function needKey(): void
    {
        if (null === $this->key || '' === $this->key) {
            throw new InvalidConfigException('[iucn] An IUCN Red List API token (the "key" option, from an account at api.iucnredlist.org) is needed for every call.');
        }
    }

    /**
     * @param array<string, mixed>      $t          the taxon
     * @param array<string, mixed>|null $a          an assessment read whole
     * @param array<string, mixed>|null $latest     the latest global assessment's summary
     */
    private function toEntity(array $t, ?array $a, ?array $latest = null, ?int $assessments = null): Entity
    {
        $id = (string) $t['sis_id'];
        $name = self::text($t['scientific_name'] ?? null) ?? $id;
        $properties = [];
        $add = static function (string $key, string $label, string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'iucn');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', self::text($t['authority'] ?? null));
        foreach (['kingdom', 'phylum', 'class', 'order', 'family'] as $level) {
            $value = self::text($t[$level.'_name'] ?? null);
            $add($level, ucfirst($level), null === $value ? null : ucfirst(strtolower($value)));
        }
        $add('genus', 'Genus', self::text($t['genus_name'] ?? null));
        $common = [];
        $main = null;
        foreach ((array) ($t['common_names'] ?? []) as $c) {
            if (\is_array($c) && null !== ($n = self::text($c['name'] ?? null))) {
                $common[] = $n;
                if ($this->language === ($c['language']['code'] ?? null) && (null === $main || true === ($c['main'] ?? false))) {
                    $main = $n;
                }
            }
        }
        $add('common_name', 'Common name', $main);
        $add('assessed_by', 'Assessed by (IUCN SSC group)', implode('; ', array_filter(array_map(static fn (mixed $g) => \is_array($g) ? self::text($g['name'] ?? null) : null, (array) ($t['ssc_groups'] ?? [])))));
        $add('assessment_count', 'Assessments (in the Red List)', $assessments);
        $category = \is_array($a['red_list_category'] ?? null) ? $a['red_list_category'] : null;
        if (null !== $category && null !== ($code = self::text($category['code'] ?? null))) {
            $add('iucn_status', 'IUCN Red List category', (self::text($category['description']['en'] ?? null) ?? $code).' ('.$code.')');
        }
        $add('red_list_criteria', 'Red List criteria', self::text($a['criteria'] ?? null));
        $add('population_trend', 'Population trend', self::text($a['population_trend']['description']['en'] ?? null));
        $add('assessment_year', 'Assessment published', self::text($a['year_published'] ?? null) ?? self::text($latest['year_published'] ?? null));
        $add('assessment_date', 'Assessed on', null !== ($d = self::text($a['assessment_date'] ?? null)) ? substr($d, 0, 10) : null);
        $scopes = array_filter(array_map(static fn (mixed $s) => \is_array($s) ? self::text($s['description']['en'] ?? null) : null, (array) ($a['scopes'] ?? $latest['scopes'] ?? [])));
        $add('assessment_scope', 'Assessment scope', $scopes ? implode(', ', $scopes) : null);
        $add('possibly_extinct', 'Possibly extinct', isset($a['possibly_extinct']) ? (bool) $a['possibly_extinct'] : null);
        $add('possibly_extinct_in_the_wild', 'Possibly extinct in the wild', isset($a['possibly_extinct_in_the_wild']) ? (bool) $a['possibly_extinct_in_the_wild'] : null);
        $add('population_size', 'Population size', self::text($a['supplementary_info']['population_size'] ?? null));
        $add('generation_length', 'Generation length (years)', self::text($a['supplementary_info']['generational_length'] ?? null));
        $add('citation', 'Citation (as the IUCN asks)', self::text($a['citation'] ?? null));
        $synonyms = [];
        foreach ((array) ($t['synonyms'] ?? []) as $s) {
            $sn = \is_array($s) ? self::text($s['name'] ?? null) ?? trim(self::text($s['genus_name'] ?? null).' '.self::text($s['species_name'] ?? null)) : null;
            if (null !== $sn && '' !== $sn) {
                $synonyms[] = $sn;
            }
        }
        $url = self::text($a['url'] ?? null) ?? self::text($latest['url'] ?? null) ?? 'https://www.iucnredlist.org/species/'.$id;
        $children = [];
        foreach (['species_taxa', 'subpopulation_taxa', 'infrarank_taxa'] as $group) {
            foreach ((array) ($t[$group] ?? []) as $c) {
                $children[] = \is_array($c) ? Identifier::tryOf(Scheme::IUCN, $c['sis_id'] ?? null) : null;
            }
        }

        return new Entity(
            id: Identifier::of(Scheme::IUCN, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: true === ($t['species'] ?? null) ? 'species' : (true === ($t['subpopulation'] ?? null) ? 'subpopulation' : (true === ($t['infrarank'] ?? null) ? 'infrarank' : null)),
            synonyms: array_values(array_unique([...$common, ...$synonyms])),
            properties: $properties,
            url: $url,
            source: 'iucn',
            related: array_filter(['child' => new Identifiers($children)], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }
}
