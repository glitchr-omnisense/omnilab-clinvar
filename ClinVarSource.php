<?php

namespace Omnilab\ClinVar;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ClinVar, the NCBI's archive of human variants and their clinical
 * significance, through the E-utilities: a variation by its VariationID
 * ("15333") or its VCV accession ("VCV000015333") - its name, type,
 * germline classification, review status and date, the conditions it was
 * classified for, its genes, protein change, molecular consequence,
 * location, SPDI, allele frequencies, aliases, submissions -, its dbSNP id
 * as a cross-reference, its genes (NCBI Gene) and its conditions (Mondo,
 * OMIM, Orphanet, HPO) as related things; a search with ClinVar's query
 * language ("rs334", "HBB[gene] AND pathogenic[clinsig]").
 *
 * No key needed. NCBI asks every caller for its tool's name and an email
 * address, and for three calls a second at most without an API key, ten
 * with one: both are sent, and a 0.34 s (or 0.1 s) throttle kept. One call
 * a variation (esummary), two a search page (esearch, esummary).
 */
final class ClinVarSource extends HttpSource
{
    public const BASE_URI = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

    /** The most variations a search page holds (esummary takes them in one call). */
    public const MAX_LIMIT = 200;

    /** ClinVar's names of the condition databases, and the schemes they are read into. */
    private const CONDITIONS = ['MONDO' => Scheme::MONDO, 'OMIM' => Scheme::OMIM, 'Orphanet' => Scheme::ORPHANET, 'Human Phenotype Ontology' => Scheme::HP];

    /**
     * @param string|null $email  sent with the tool's name, as NCBI asks: whom it writes to before it blocks a caller
     * @param float|null  $throttle seconds between two calls; null: NCBI's, 0.34 without a key, 0.1 with one
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $apiKey = null,
        private readonly ?string $email = null,
        private readonly string $tool = 'omnilab',
        string $baseUri = self::BASE_URI,
        array $headers = [],
        ?float $throttle = null,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle ?? (null === $apiKey ? 0.34 : 0.1));
    }

    public function getName(): string
    {
        return 'clinvar';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A variation by its VariationID ("15333", "clinvar:15333") or VCV
     * accession ("VCV000015333", versioned or not, its page); a dbSNP id
     * names a site, which may hold several variations (rs334: 15333, the
     * sickle cell allele, and 15175): it is refused, as any other base's
     * identifier, before any call - search() takes it; null when ClinVar has
     * no such variation.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::CLINVAR) ?? throw NotSupportedException::identifier('clinvar', (string) $id);

        return $this->summaries([$identifier->value])[0] ?? null;
    }

    /**
     * The variations matching a text in ClinVar's query language (an rs id,
     * a gene, a condition, fields as "HBB[gene] AND pathogenic[clinsig]"),
     * in ClinVar's order; a domain other than biology answers nothing.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $start = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_LIMIT, $query->limit));
        $result = $this->getJson('esearch.fcgi', ['db' => 'clinvar', 'term' => $text, 'retstart' => $start, 'retmax' => $limit, 'retmode' => 'json'] + $this->identity())['esearchresult'] ?? [];
        $ids = array_values(array_filter(array_map('strval', (array) ($result['idlist'] ?? []))));
        $total = isset($result['count']) ? (int) $result['count'] : null;

        return new Page($ids ? $this->summaries($ids) : [], null !== $total && $start + $limit < $total ? (string) ($start + $limit) : null, $total);
    }

    /**
     * @param list<string> $ids VariationIDs
     *
     * @return list<Entity> in the order asked, the unknown ones left out
     */
    private function summaries(array $ids): array
    {
        $result = $this->getJson('esummary.fcgi', ['db' => 'clinvar', 'id' => implode(',', $ids), 'retmode' => 'json'] + $this->identity())['result'] ?? [];
        $entities = [];
        foreach ($ids as $id) {
            $summary = $result[$id] ?? null;
            if (\is_array($summary) && !isset($summary['error']) && null !== ($entity = $this->variation($summary))) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /** @param array<string, mixed> $v an esummary document */
    private function variation(array $v): ?Entity
    {
        $id = Identifier::tryOf(Scheme::CLINVAR, $v['uid'] ?? null);
        if (null === $id) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'clinvar');
            }
        };
        $text = static fn (mixed $value): ?string => \is_string($value) && '' !== trim($value) ? trim($value) : null;
        $add('accession', 'Accession', $text($v['accession_version'] ?? null));
        $add('variant_type', 'Variant type', $text($v['obj_type'] ?? null));
        $related = ['gene' => [], 'condition' => []];
        foreach (['germline_classification' => ['', 'Germline classification'], 'clinical_impact_classification' => ['clinical_impact_', 'Clinical impact (somatic)'], 'oncogenicity_classification' => ['oncogenicity_', 'Oncogenicity']] as $field => [$prefix, $label]) {
            $classification = $v[$field] ?? null;
            if (!\is_array($classification) || null === $text($classification['description'] ?? null)) {
                continue;
            }
            $add($prefix ? $prefix.'classification' : 'classification', $label, $text($classification['description']));
            $add($prefix.'review_status', 'Review status'.($prefix ? ' ('.lcfirst($label).')' : ''), $text($classification['review_status'] ?? null));
            $add($prefix.'last_evaluated', 'Last evaluated'.($prefix ? ' ('.lcfirst($label).')' : ''), self::date($classification['last_evaluated'] ?? null));
            $names = [];
            foreach ((array) ($classification['trait_set'] ?? []) as $trait) {
                $name = \is_array($trait) ? $text($trait['trait_name'] ?? null) : null;
                if (null !== $name && !isset($names[$name])) {
                    $names[$name] = true;
                    $add($prefix.'condition', 'Condition'.($prefix ? ' ('.lcfirst($label).')' : ''), $name);
                }
                foreach (\is_array($trait) ? (array) ($trait['trait_xrefs'] ?? []) : [] as $xref) {
                    $scheme = self::CONDITIONS[$xref['db_source'] ?? ''] ?? null;
                    $related['condition'][] = null === $scheme ? null : Identifier::tryOf($scheme, $xref['db_id'] ?? null);
                }
            }
        }
        foreach ((array) ($v['genes'] ?? []) as $gene) {
            if (\is_array($gene)) {
                $add('gene', 'Gene', $text($gene['symbol'] ?? null));
                $related['gene'][] = Identifier::tryOf(Scheme::NCBI_GENE, $gene['geneid'] ?? null);
            }
        }
        $add('protein_change', 'Protein change', $text($v['protein_change'] ?? null));
        foreach ((array) ($v['molecular_consequence_list'] ?? []) as $consequence) {
            $add('molecular_consequence', 'Molecular consequence', $text($consequence));
        }
        $add('submissions', 'Submissions', isset($v['supporting_submissions']['scv']) ? \count((array) $v['supporting_submissions']['scv']) : null);
        $identifiers = [];
        $aliases = [];
        foreach ((array) ($v['variation_set'] ?? []) as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            $add('cdna_change', 'cDNA change', $text($variant['cdna_change'] ?? null));
            $add('spdi', 'Canonical SPDI', $text($variant['canonical_spdi'] ?? null));
            foreach ((array) ($variant['variation_loc'] ?? []) as $location) {
                if (\is_array($location) && 'current' === ($location['status'] ?? null) && null !== $text($location['chr'] ?? null)) {
                    $start = $text($location['start'] ?? null);
                    $stop = $text($location['stop'] ?? null);
                    $add('genomic_location', 'Genomic location', $location['chr'].':'.$start.($stop !== $start ? '-'.$stop : '').', '.($text($location['assembly_name'] ?? null) ?? '?'));
                    $add('map_location', 'Cytogenetic location', $text($location['band'] ?? null));
                }
            }
            foreach ((array) ($variant['allele_freq_set'] ?? []) as $frequency) {
                if (\is_array($frequency) && is_numeric($frequency['value'] ?? null)) {
                    $add('allele_frequency', 'Allele frequency ('.($text($frequency['source'] ?? null) ?? '?').')', (float) $frequency['value']);
                }
            }
            foreach ((array) ($variant['aliases'] ?? []) as $alias) {
                if (null !== ($alias = $text($alias))) {
                    $aliases[] = $alias;
                }
            }
            foreach ((array) ($variant['variation_xrefs'] ?? []) as $xref) {
                if ('dbSNP' === ($xref['db_source'] ?? null)) {
                    $identifiers[] = Identifier::tryOf(Scheme::DBSNP, 'rs'.($xref['db_id'] ?? ''));
                }
            }
        }
        $label = $text($v['title'] ?? null) ?? $id->display();

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::BIOLOGY,
            kind: $text($v['obj_type'] ?? null) ?? 'variation',
            synonyms: array_values(array_unique($aliases)),
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: $id->url(),
            source: 'clinvar',
            related: array_filter(array_map(static fn (array $ids) => new Identifiers($ids), $related), static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** "2026/07/01 00:00" as "2026-07-01"; ClinVar's empty date ("1/01/01 00:00") as null. */
    private static function date(mixed $date): ?string
    {
        return \is_string($date) && preg_match('~^(\d{4})/(\d{2})/(\d{2})~', $date, $m) ? $m[1].'-'.$m[2].'-'.$m[3] : null;
    }

    /** @return array<string, string> the tool, the contact address and the key E-utilities take */
    private function identity(): array
    {
        return array_filter(['tool' => $this->tool, 'email' => $this->email, 'api_key' => $this->apiKey], static fn (?string $v) => null !== $v && '' !== $v);
    }
}
