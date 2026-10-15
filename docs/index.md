# omnilab/iucn

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

## Verified, and not

**Not tested live**: no token was used (an account and the acceptance of the terms of use are the
user's). On 2026-10-16 the API answered 403 to a call without a token, and its documentation sat
behind a browser challenge. WoRMS gave the IUCN id of the common dolphin (134817215) that day.

The tests answer the bodies the API v4 gave rredlist on 2024-09-18 (`Tests/Constructed/`): the
gorilla's taxon (SIS 9404, twelve assessments, its latest global one chosen) by id and by name,
the Atlantic Puffin's Europe assessment (Endangered, A2abcde+4abcde, decreasing). Whether today's
answers keep that shape, and whether the token is sent as rredlist sends it, is to be checked by a
user with a token.
