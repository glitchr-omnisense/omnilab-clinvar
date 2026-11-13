# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab ClinVar: a variation by its VariationID or VCV accession - its name, type, germline
  classification, review status, conditions, genes, protein change, consequence, location, SPDI,
  allele frequencies, aliases - its dbSNP id as a cross-reference, its genes and conditions (Mondo,
  OMIM, Orphanet, HPO) as related things, and a search in ClinVar's query language, through the
  NCBI's E-utilities, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/clinvar` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
