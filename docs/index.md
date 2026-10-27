# omnilab/clinvar

ClinVar, through the NCBI's E-utilities (`https://eutils.ncbi.nlm.nih.gov/entrez/eutils/`, database
`clinvar`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): variations by
VariationID or VCV accession - classification, review status, conditions, genes, protein change,
location, SPDI, allele frequencies - with their dbSNP id, as entities, their genes and conditions as
related things; a search in ClinVar's query language. No key needed.

## Installation

```sh
composer require omnilab/clinvar
```

```php
use Omnilab\ClinVar\ClinVarSourceFactory;

$clinvar = (new ClinVarSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // E-utilities' "email", as NCBI asks
    'api_key' => getenv('NCBI_API_KEY') ?: null,   // optional: ten calls a second instead of three
    'tool' => 'omnilab',               // optional: E-utilities' "tool"
    'retries' => 1,                    // optional: a call NCBI answers 429 made again after its wait
]);
```

## Credentials

None needed: the E-utilities answer three calls a second without a key. An NCBI API key (an NCBI
account's settings, ncbi.nlm.nih.gov) raises it to ten: give it as `api_key`. NCBI asks every
caller for the tool's name and an email address: `tool` and `mailto` are sent with every call.

## Options

The factory's options, with the defaults it applies (`ClinVarSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api_key` | `?string` | null | no | an NCBI API key, sent as `api_key`; ten calls a second instead of three |
| `tool` | `string` | `omnilab` | no | E-utilities' `tool`: the name NCBI knows the application by |
| `retries` | `int` | `1` | no | how many times a call NCBI answers 429 is made again, after the wait it asks (a wait over 10 s is not waited); then a `RateLimitedException` |
| `mailto` | `?string` | null | no | a contact address: E-utilities' `email` with every call, and in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float\|string` | `auto` | no | seconds left between two calls; `auto`: 0.34 without a key, 0.1 with one, as NCBI asks |
| `base_uri` | `string` | `https://eutils.ncbi.nlm.nih.gov/entrez/eutils/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a VariationID (`15333`, `clinvar:`, `VCV000015333`, versioned or not, its page); a dbSNP id, an RCV or SCV accession, another base refused before any call; one call |
| `search` | yes | ClinVar's query language (an rs id, a gene, a condition, fields: `HBB[gene] AND pathogenic[clinsig]`), the most recent first, 200 a page at most, with a `total`; two calls a page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | its dbSNP id; its genes (NCBI Gene) and conditions (Mondo, OMIM, Orphanet, HPO) are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## The scheme

A variation is named in the core's `clinvar` scheme by its VariationID (`clinvar:15333`;
`display()` gives the VCV accession, `VCV000015333`), read from the VCV accession (versioned or
not), from `clinvar:` and from its page (`https://www.ncbi.nlm.nih.gov/clinvar/variation/15333/`).
To this source a bare number is a VariationID.

A dbSNP id (`dbsnp:rs334`) names a site, which may hold several variations - rs334 holds the sickle
cell allele (15333, c.20A>T, pathogenic), another allele (15175, c.20A>C, likely benign) and eight
haplotypes -: `entity()` refuses it, `search('rs334')` lists them. An RCV or SCV accession (a
record, a submission) is not a variation: refused.

## Which API

The E-utilities, `https://eutils.ncbi.nlm.nih.gov/entrez/eutils/`, database `clinvar`, JSON:

| Method | Calls |
|---|---|
| `entity()` | `esummary.fcgi?db=clinvar&id=15333` |
| `search()` | `esearch.fcgi?db=clinvar&term=...&retstart=...&retmax=...`, then `esummary.fcgi` for the page's ids (none for an empty page) |

Every call carries `tool` and `email` (and `api_key` when configured), as NCBI asks; the source
waits 0.34 s between two calls without a key, 0.1 s with one. NCBI's limit is per address: on
2026-10-15 a 429 came twice while other clients shared the address (NCBI's `Retry-After`: 2 s).
The source makes such a call again once after the wait NCBI asks (`retries`, 1 by default; a wait
over 10 s is not waited), then throws a `RateLimitedException`. An unknown VariationID is `null` (esummary answers "cannot
get document summary").

## What an entity holds

`id`, `label` (ClinVar's title: `NM_000518.5(HBB):c.20A>T (p.Glu7Val)`), `kind` (the variant
type: `single nucleotide variant`, `Haplotype`, `Deletion`...), `synonyms` (its aliases: `E6V`,
`HbS`), `url` (its page), and:

| Key | esummary | Value |
|---|---|---|
| `accession` | accession_version | `VCV000015333.183` |
| `variant_type` | obj_type | `single nucleotide variant` |
| `classification`, `review_status`, `last_evaluated` | germline_classification | `Pathogenic`, `criteria provided, multiple submitters, no conflicts`, `2026-07-01` |
| `condition` (each once) | its trait names | `Hb SS disease`, `beta Thalassemia`... |
| `clinical_impact_classification`, `oncogenicity_classification` (and their review status, date, conditions) | the somatic classifications, when given | text |
| `gene` (one each) | genes | `HBB`, `LOC106099062`... |
| `protein_change`, `molecular_consequence`, `cdna_change` | protein_change, molecular_consequence_list, cdna_change | `E7V`, `missense variant`, `c.20A>T` |
| `genomic_location`, `map_location` | the current location | `11:5227002, GRCh38`, `11p15.4` |
| `spdi` | canonical_spdi | `NC_000011.10:5227001:T:A` |
| `allele_frequency` (one per source) | allele_freq_set | `0.00348`, labelled with its source (gnomAD exomes, gnomAD, ExAC, 1000 Genomes) |
| `submissions` | supporting_submissions | how many |

`identifiers`: its own, its dbSNP id (`dbsnp:rs334`; a haplotype has one per variant).
`related`: `gene` (NCBI Gene: `ncbi.gene:3043`), `condition` (the conditions' Mondo, OMIM,
Orphanet and HPO ids; MedGen and MeSH have no scheme in the core). The `Collector` follows none of
them: `$registry->get('ncbi')->entity($variant->related('gene')->get(Scheme::NCBI_GENE))` reads the
gene.

The `Collector` walks from ClinVar into the GWAS Catalog by the rs id (omnilab/gwas reads `dbsnp`):
`$collector->collect('clinvar', '15333', 1, ['gwas'])`, three calls in 1.7 s on 2026-10-15, ClinVar's
classification and the catalogue's associations side by side. Into ClinVar: omnilab/wikidata
reads an item's ClinVar Variation ID (P1929) as a cross-reference, so the `Collector` follows a
Wikidata item into ClinVar - and offers a ClinVar variation's id to Wikidata, which looks items up
by it.

## The search

ClinVar's query language, as on its site: an rs id, a gene, a condition, a protein change, fields
(`HBB[gene] AND pathogenic[clinsig]`: 634 variations on 2026-10-15), in ClinVar's order (the most
recent first), paged by `retstart`.

## What the API does not give here

The submissions themselves (SCV: each submitter's classification and evidence), the RCV records
(variation-condition pairs), the variation's full XML (`efetch` with `rettype=vcv`: HGVS on every
transcript, the review's details), MedGen's concepts, and the somatic classifications' details.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\ClinVar\ClinVarSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$clinvar = (new ClinVarSourceFactory(HttpClient::create()))->create([
    'mailto' => 'you@example.org',                 // E-utilities' "email"
    'api_key' => getenv('NCBI_API_KEY') ?: null,   // optional: ten calls a second instead of three
]);

$hbs = $clinvar->entity('VCV000015333');
echo $hbs->id->display(), ' ', $hbs->label, ': ', $hbs->value('classification'), ' (', $hbs->value('review_status'), '), ', $hbs->identifier(Scheme::DBSNP), ', ', count($hbs->properties('condition')), " conditions\n";

$page = $clinvar->search(new Query('HBB[gene] AND pathogenic[clinsig]', limit: 3));
printf("%d pathogenic variations of HBB; the latest %d:\n", $page->total, count($page));
foreach ($page as $variation) {
    echo '  ', $variation->id->display(), '  ', $variation->label, "\n";
}
```

```
VCV000015333 NM_000518.5(HBB):c.20A>T (p.Glu7Val): Pathogenic (criteria provided, multiple submitters, no conflicts), rs334, 16 conditions
635 pathogenic variations of HBB; the latest 3:
  VCV005019560  NM_000518.5(HBB):c.91_93-3del (p.Arg31=)
  VCV005008196  NM_000518.5(HBB):c.11T>G (p.Leu4Arg)
  VCV004974749  GRCh38/hg38 11p15.4(chr11:4793595-7562692)x1
```

(as answered on 2026-10-27, without a key: three calls, 0.34 s apart)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        clinvar: { factory: clinvar, options: { mailto: '%env(OMNILAB_MAILTO)%', api_key: '%env(default::NCBI_API_KEY)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

ClinVar's own: a 429 is made again `retries` times after the wait NCBI's `Retry-After` asks (10 s
at most), then thrown as a `RateLimitedException`. An unknown VariationID is null (esummary
answers "cannot get document summary"). Before any call: a dbSNP id, an RCV or SCV accession, an
identifier of another base (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15: the sickle cell variant VCV000015333 (pathogenic, 16
conditions, 74 submissions, five allele frequencies, rs334), by its VariationID, its accession and
its page; an unknown VariationID; the search for rs334 (ten variations, in pages of three) and for
HBB's pathogenic variations; its gene read from NCBI Gene. The fixtures in `Tests/Fixtures/` are its
answers. Each call took 0.3 to 0.8 s.

Again on 2026-10-27, without a key: VCV000015333 (pathogenic, 16 conditions, rs334) and HBB's
pathogenic variations (635; the example above).

Not verified: a somatic (clinical impact, oncogenicity) classification (the code reads the fields
the summary holds; none was met here); an API key (none used).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NCBI or the U.S. National Library of Medicine. ClinVar asks whoever distributes or
copies its data to attribute ClinVar and cite one of its publications
([its usage page](https://www.ncbi.nlm.nih.gov/clinvar/docs/maintenance_use/)), and the
E-utilities ask for the tool's name, an email and their rate, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-clinvar#terms-of-use) says. Whoever runs the package is responsible for complying
with these terms and for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-clinvar#responsibility) section.
