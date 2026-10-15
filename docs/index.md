# omnilab/clinvar

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
]);
```

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
2026-10-15 a 429 came once while other clients shared the address - a `RateLimitedException`
(retry after 2 s, NCBI's `Retry-After`). An unknown VariationID is `null` (esummary answers "cannot
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
classification and the catalogue's associations side by side. No source reads ClinVar's own ids:
there is no path into ClinVar.

## The search

ClinVar's query language, as on its site: an rs id, a gene, a condition, a protein change, fields
(`HBB[gene] AND pathogenic[clinsig]`: 634 variations on 2026-10-15), in ClinVar's order (the most
recent first), paged by `retstart`.

## What the API does not give here

The submissions themselves (SCV: each submitter's classification and evidence), the RCV records
(variation-condition pairs), the variation's full XML (`efetch` with `rettype=vcv`: HGVS on every
transcript, the review's details), MedGen's concepts, and the somatic classifications' details.

## Verified, and not

Against the real service on 2026-10-15: the sickle cell variant VCV000015333 (pathogenic, 16
conditions, 74 submissions, five allele frequencies, rs334), by its VariationID, its accession and
its page; an unknown VariationID; the search for rs334 (ten variations, in pages of three) and for
HBB's pathogenic variations; its gene read from NCBI Gene. The fixtures in `Tests/Fixtures/` are its
answers. Each call took 0.3 to 0.8 s.

Not verified: a somatic (clinical impact, oncogenicity) classification (the code reads the fields
the summary holds; none was met here); an API key (none used).
