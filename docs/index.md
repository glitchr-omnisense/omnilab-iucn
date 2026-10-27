# omnilab/iucn

The IUCN Red List API v4 (`https://api.iucnredlist.org/api/v4/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a taxon by its SIS id or its
scientific name, with its latest global assessment - category, criteria, population trend,
citation -, as an entity. The user's token is required. Not tested live.

## Installation

```sh
composer require omnilab/iucn
```

```php
use Omnilab\Iucn\IucnSourceFactory;

$iucn = (new IucnSourceFactory($httpClient))->create([
    'key' => getenv('IUCN_API_KEY'),  // the user's Red List API token
]);
```

With the Symfony bundle, the factory is registered as `iucn` (see the core's docs); keep the token
in an environment variable or the secrets vault.

## Credentials

Every call needs the user's Red List API token: an account at
[api.iucnredlist.org](https://api.iucnredlist.org/), under the IUCN Red List Terms of Use, gives
it. It is given as `key` and sent in the `Authorization` header, as the IUCN's R client rredlist
sends it. Without it the source is built and every call is refused before it is made. Keep the
token in an environment variable or the secrets vault.

## Options

The factory's options, with the defaults it applies (`IucnSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `key` | `?string` | null | no | the user's Red List API token, sent as `Authorization`; null or empty: every call refused (`InvalidConfigException`) before it is made |
| `assessment` | `bool` | `true` | no | `entity()` reads the latest global assessment too (a second call); `false`: the taxon only |
| `language` | `string` | `eng` | no | the common name kept as `common_name`, by its ISO 639-3 code |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.5` | no | seconds left between two calls: the IUCN asks for half a second at least |
| `base_uri` | `string` | `https://api.iucnredlist.org/api/v4/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a SIS id (`134817215`, `iucn:`, its page) or a scientific name of two or three words (`ssp.`, `var.` read); another scheme refused before any call; a name of one word is null |
| `search` | no | the API has no search of names |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: the Red List gives no other base's id (WoRMS and Wikidata carry the IUCN id) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Iucn\IucnSource`: `assessment(int|string $id): ?Entity`, the
taxon with what one assessment (of any scope: a regional one) says.

## Which API

The IUCN Red List API v4, `https://api.iucnredlist.org/api/v4/`. The token goes in the
`Authorization` header, as the IUCN's R client rredlist sends it. Its documentation
(`api.iucnredlist.org/api-docs`) sat behind a browser challenge on 2026-10-16 (403 to a script):
the calls and the shape of the answers are taken from rredlist (its source and its recorded test
fixtures, 2024-09-18).

## Calls

| Method | Reads | Red List |
|---|---|---|
| `entity()` | a SIS id: `134817215`, `iucn:134817215`, `https://www.iucnredlist.org/species/134817215` | `taxa/sis/{id}`, then `assessment/{id}` of its latest global assessment |
| | a scientific name: genus and species, a subspecies or variety third (`ssp.`, `var.` read) | `taxa/scientific_name?genus_name=&species_name=&infra_name=`, then the assessment |
| `assessment()` | an assessment id, outside the contract: the taxon, with what that assessment (of any scope) says | `assessment/{id}` |

No `search()`: the API has no search of names. An unknown taxon is null (404). An identifier of
another scheme is refused before any call; a name of one word is null without a call.

## What an entity holds

`id` (`iucn:` its SIS id), `label`, `domain` taxonomy, `kind` (`species`, `subpopulation`,
`infrarank`), `synonyms` (its common names, then its synonyms), `url` (its assessment's page),
`related` (`child`: its subspecies and subpopulations), and:

| Key | Red List | Value |
|---|---|---|
| `taxon_name`, `taxon_authority` | scientific_name, authority | text |
| `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the taxon's names (capitalised: the API writes them upper case) | text |
| `common_name` | the main common name in `language` | text |
| `assessed_by` | the IUCN SSC groups | text |
| `assessment_count` | its assessments | number |
| `iucn_status` | red_list_category: `Endangered (EN)` (GBIF's and iNaturalist's key and form) | text |
| `red_list_criteria`, `population_trend` | criteria, population_trend | text |
| `assessment_year`, `assessment_date`, `assessment_scope` | year_published, assessment_date (its day), scopes | text |
| `possibly_extinct`, `possibly_extinct_in_the_wild` | | boolean |
| `population_size`, `generation_length` | supplementary_info | text |
| `citation` | the assessment's citation, to give with it | text |

Which assessment: the one marked `latest` among those of the global scope (code 1); a taxon
assessed only regionally has none here (`assessment()` reads a regional one by its id).

## What the Red List does not give here

- Not read: threats, habitats, conservation actions, use and trade, locations and countries,
  realms, stresses, references and credits of an assessment; the lists by country, family,
  category or threat; the range maps (spatial data are distributed apart, under their own terms).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Exception\InvalidConfigException;
use Omnilab\Iucn\IucnSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$iucn = (new IucnSourceFactory(HttpClient::create()))->create([
    'key' => getenv('IUCN_API_KEY') ?: null,   // the user's Red List API token: every call needs it
    'mailto' => 'you@example.org',
]);

try {
    $gorilla = $iucn->entity('Gorilla gorilla');
    echo $gorilla?->label, ': ', $gorilla?->value('iucn_status'), ' (', $gorilla?->value('red_list_criteria'), '), ', $gorilla?->value('population_trend'), "\n";
    echo $gorilla?->value('citation'), "\n";
} catch (InvalidConfigException $e) {
    echo $e->getMessage(), "\n";
}
```

Without a token (run on 2026-10-27, no network needed: the call is refused before it is made):

```
[iucn] An IUCN Red List API token (the "key" option, from an account at api.iucnredlist.org) is needed for every call.
```

With a token it prints the gorilla's category, criteria, trend and the assessment's citation: not
run here (no token).

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        iucn: { factory: iucn, options: { key: '%env(IUCN_API_KEY)%', mailto: '%env(OMNILAB_MAILTO)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call: no token (`InvalidConfigException`), an identifier of another scheme
(`NotSupportedException`). The API's 403 (no token, a wrong one) is a `ProviderException`; an
unknown taxon its 404, null.

## Verified, and not

**Not tested live**: no token was used (an account and the acceptance of the terms of use are the
user's). On 2026-10-16 the API answered 403 to a call without a token, and its documentation sat
behind a browser challenge. WoRMS gave the IUCN id of the common dolphin (134817215) that day.

The tests answer the bodies the API v4 gave rredlist on 2024-09-18 (`Tests/Constructed/`): the
gorilla's taxon (SIS 9404, twelve assessments, its latest global one chosen) by id and by name,
the Atlantic Puffin's Europe assessment (Endangered, A2abcde+4abcde, decreasing). Whether today's
answers keep that shape, and whether the token is sent as rredlist sends it, is to be checked by a
user with a token.

On 2026-10-27 the example below ran without a token: the refusal before any call, as tested.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the IUCN. The token is the user's, under the
[IUCN Red List Terms of Use](https://www.iucnredlist.org/terms/terms-of-use): conservation,
education and research that is not commercial (a commercial use needs the IUCN's licence), no
redistribution of the data as a dataset, the Red List and each assessment cited, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-iucn#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-iucn#responsibility)
section.
