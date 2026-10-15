# omnilab/iucn

> **Not tested live**: every call of the IUCN Red List API needs a token, obtained with an account
> under the Red List's terms of use; none was used. On 2026-10-16 the API answered 403 without one.
> The tests answer the bodies the API v4 gave the IUCN's R client, rredlist, as recorded in its
> public test fixtures (`Tests/Constructed/`, whose README says where each comes from).

The IUCN Red List of Threatened Species for
[glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a taxon by its SIS id or its
scientific name - its classification, authority, common names, synonyms, the IUCN group that
assesses it - with its latest global assessment: its Red List category, criteria, population
trend, year, whether it is possibly extinct, and the citation the IUCN asks for. An assessment by
its id. With the user's API token.

```php
$iucn = (new IucnSourceFactory($http))->create(['key' => $_ENV['IUCN_API_KEY']]);
$dolphin = $iucn->entity('134817215');            // or 'iucn:134817215', its page, 'Delphinus delphis'
$dolphin->value('iucn_status');                   // e.g. "Least Concern (LC)": the key GBIF and iNaturalist use too
$dolphin->value('population_trend');
$dolphin->value('citation');                      // the citation to give with it

$iucn->assessment(166290968);                     // one assessment, any scope (outside the contract)
```

```yaml
omnilab:
    sources:
        iucn:
            factory: iucn
            options:
                key: '%env(IUCN_API_KEY)%'   # the user's Red List API token; every call needs it
                assessment: true             # entity() reads the latest global assessment (a second call)
                language: eng                # the common name kept
```

Without a token every call is refused before it is made (`InvalidConfigException`, naming where it
is obtained). A taxon is named in the core's `iucn` scheme, which WoRMS gives (`omnilab/worms`
carries the IUCN id of a marine taxon: the common dolphin's 134817215) and Wikidata holds (P627):
the `Collector` follows it here. The category is written `Endangered (EN)`, as `omnilab/gbif` and
`omnilab/inaturalist` write theirs, under the same key, `iucn_status`: merged, the three are kept
side by side, each with its source.

## Terms of use

The Red List API is the IUCN's. Its token is obtained by the user with an account at
[api.iucnredlist.org](https://api.iucnredlist.org/), under the
[IUCN Red List Terms of Use](https://www.iucnredlist.org/terms/terms-of-use): among them, use
for conservation, education and research that is not commercial - a commercial use needs the
IUCN's licence (for business, through IBAT) -, no redistribution of the data as a dataset, and
the citation of the Red List ("IUCN [year]. The IUCN Red List of Threatened Species. Version
[version]. https://www.iucnredlist.org. Accessed on [date]") and of each assessment (`citation`).
The IUCN asks for half a second at least between two calls: the source waits 0.5 s. Obtaining
the token, and keeping to these terms, are the user's.

See [docs/](docs/index.md) for what is read, how it maps, and what the Red List does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the International Union for Conservation of Nature (IUCN). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
