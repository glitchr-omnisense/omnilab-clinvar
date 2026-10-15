# omnilab/clinvar

ClinVar for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab), through the NCBI's
E-utilities: a variation by its VariationID (`15333`) or VCV accession (`VCV000015333`) with its
name, type, germline classification, review status and date, the conditions it was classified
for, its genes, protein change, molecular consequence, location, SPDI, allele frequencies, aliases
and number of submissions; its dbSNP id as a cross-reference, its genes (NCBI Gene) and its
conditions (Mondo, OMIM, Orphanet, HPO) as related things; a search in ClinVar's query language.
No key needed.

```php
$clinvar = (new ClinVarSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$hbs = $clinvar->entity('VCV000015333');
$hbs->label;                              // NM_000518.5(HBB):c.20A>T (p.Glu7Val)
$hbs->value('classification');            // Pathogenic
$hbs->value('review_status');             // criteria provided, multiple submitters, no conflicts
$hbs->synonyms;                           // E6V, HbS
$hbs->identifiers()->keys();              // clinvar:15333, dbsnp:rs334
$hbs->related('condition')->keys();       // mondo:0020989, omim:141749, ..., mondo:0011382, ...
$clinvar->search(new Query('HBB[gene] AND pathogenic[clinsig]', limit: 5));   // 634 variations
```

```yaml
omnilab:
    sources:
        clinvar:
            factory: clinvar
            options:
                mailto: 'support@glitchr.io'   # E-utilities' email, as NCBI asks, and the User-Agent
                api_key: '%env(NCBI_API_KEY)%' # optional: ten calls a second instead of three
```

A variation is named in the core's `clinvar` scheme (`clinvar:15333`, displayed `VCV000015333`).
The `Collector` walks from ClinVar into the GWAS Catalog by its rs id
(`$collector->collect('clinvar', '15333', 1, ['gwas'])`).

## Terms of use

ClinVar asks whoever distributes or copies its data to attribute ClinVar as a data source and to
cite one of its publications ([its usage page](https://www.ncbi.nlm.nih.gov/clinvar/docs/maintenance_use/)):
Landrum M. J. et al., "ClinVar: updates to support classifications of both germline and somatic
variants", Nucleic Acids Res. 2025, [10.1093/nar/gkae1090](https://doi.org/10.1093/nar/gkae1090).
The E-utilities ask for the tool's name and an email address with every call, and three calls a
second at most without an API key (ten with one): the source sends both and waits 0.34 s (0.1 s)
between two calls. The key is an option, never written in the package.

License: MIT.
