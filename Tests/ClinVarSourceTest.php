<?php

namespace Omnilab\ClinVar\Tests;

use Omnilab\ClinVar\ClinVarSource;
use Omnilab\ClinVar\ClinVarSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from eutils.ncbi.nlm.nih.gov on 2026-10-15: the
 * summary of the sickle cell variant (VariationID 15333, HBB c.20A>T,
 * p.Glu7Val), of an id ClinVar does not have; the search for "rs334" (ten
 * variations: two alleles and eight haplotypes) in pages of three, its
 * first page's and its last page's summaries; a search nothing matches.
 */
final class ClinVarSourceTest extends TestCase
{
    /** @var list<array<string, string>> the queries asked, tool and email aside */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): ClinVarSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('omnilab', $query['tool'] ?? null);
            self::assertSame('support@glitchr.io', $query['email'] ?? null, 'NCBI\'s rule: the tool and an address');
            self::assertSame('json', $query['retmode']);
            unset($query['tool'], $query['email'], $query['retmode']);
            $this->calls[] = ['call' => basename($path)] + $query;
            if ($response) {
                return $response;
            }
            $fixture = match (basename($path)) {
                'esummary.fcgi' => is_file($file = __DIR__.'/Fixtures/esummary-'.$query['id'].'.json') ? $file : __DIR__.'/Fixtures/esummary-notfound.json',
                'esearch.fcgi' => 'rs334' === $query['term'] ? __DIR__.'/Fixtures/esearch-rs334-'.$query['retstart'].'.json' : __DIR__.'/Fixtures/esearch-notfound.json',
            };

            return new MockResponse((string) file_get_contents($fixture));
        });
        $source = (new ClinVarSourceFactory($http))->create(['mailto' => 'support@glitchr.io', 'throttle' => 0] + $options);
        self::assertInstanceOf(ClinVarSource::class, $source);

        return $source;
    }

    public function testTheSickleCellVariant(): void
    {
        $variant = $this->source()->entity('VCV000015333.183');

        self::assertSame('clinvar:15333', $variant->id->key());
        self::assertSame('VCV000015333', $variant->id->display());
        self::assertSame('NM_000518.5(HBB):c.20A>T (p.Glu7Val)', $variant->label);
        self::assertSame(Domain::BIOLOGY, $variant->domain);
        self::assertSame('single nucleotide variant', $variant->kind);
        self::assertSame(['E6V', 'HbS'], $variant->synonyms);
        self::assertSame('https://www.ncbi.nlm.nih.gov/clinvar/variation/15333/', $variant->url);
        self::assertSame('VCV000015333.183', $variant->value('accession'));
        self::assertSame('Pathogenic', $variant->value('classification'));
        self::assertSame('criteria provided, multiple submitters, no conflicts', $variant->value('review_status'));
        self::assertSame('2026-07-01', $variant->value('last_evaluated'));
        self::assertCount(16, $variant->properties('condition'), 'each condition once');
        self::assertContains('Hb SS disease', array_map('strval', $variant->properties('condition')));
        self::assertNull($variant->property('oncogenicity_classification'), 'empty: left out');
        self::assertSame(['HBB', 'LOC106099062', 'LOC107133510'], array_map('strval', $variant->properties('gene')));
        self::assertSame('E7V', $variant->value('protein_change'));
        self::assertSame('missense variant', $variant->value('molecular_consequence'));
        self::assertSame('c.20A>T', $variant->value('cdna_change'));
        self::assertSame('NC_000011.10:5227001:T:A', $variant->value('spdi'));
        self::assertSame('11:5227002, GRCh38', $variant->value('genomic_location'));
        self::assertSame('11p15.4', $variant->value('map_location'));
        self::assertSame(74, $variant->value('submissions'));
        self::assertCount(5, $variant->properties('allele_frequency'));
        self::assertSame('Allele frequency (The Genome Aggregation Database (gnomAD), exomes)', $variant->property('allele_frequency')->label);
        self::assertSame(0.00348, $variant->value('allele_frequency'));
        self::assertSame(['clinvar:15333', 'dbsnp:rs334'], $variant->identifiers()->keys());
        self::assertSame(['ncbi.gene:3043', 'ncbi.gene:106099062', 'ncbi.gene:107133510'], $variant->related('gene')->keys());
        self::assertContains('mondo:0011382', $variant->related('condition')->keys());
        self::assertContains('omim:603903', $variant->related('condition')->keys());
        self::assertContains('orphanet:232', $variant->related('condition')->keys());
        self::assertContains('hp:0005511', $variant->related('condition')->keys());
        self::assertSame([['call' => 'esummary.fcgi', 'db' => 'clinvar', 'id' => '15333']], $this->calls);
    }

    public function testTheIdentifierInItsForms(): void
    {
        foreach (['15333', 'clinvar:15333', 'VCV000015333', 'https://www.ncbi.nlm.nih.gov/clinvar/variation/15333/', Identifier::of(Scheme::CLINVAR, '15333')] as $id) {
            self::assertSame('clinvar:15333', (string) $this->source()->entity($id)?->id, (string) $id);
        }
    }

    public function testAnUnknownVariationIsNull(): void
    {
        self::assertNull($this->source()->entity('999999999'));
    }

    public function testAnRsIdAndAnotherBaseAreRefusedBeforeAnyCall(): void
    {
        foreach (['rs334', 'HBB', 'MONDO:0011382', Identifier::of(Scheme::NCBI_GENE, '3043'), 'RCV000016573'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchInPages(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('rs334', limit: 3));

        self::assertSame(['clinvar:446748', 'clinvar:446747', 'clinvar:446738'], array_map(static fn ($e) => (string) $e->id, $first->items));
        self::assertSame('Haplotype', $first->first()->kind);
        self::assertSame(10, $first->total);
        self::assertSame('3', $first->next);
        self::assertSame([['call' => 'esearch.fcgi', 'db' => 'clinvar', 'term' => 'rs334', 'retstart' => '0', 'retmax' => '3'], ['call' => 'esummary.fcgi', 'db' => 'clinvar', 'id' => '446748,446747,446738']], $this->calls);

        $last = $source->search(new Query('rs334', limit: 3, cursor: '9'));
        self::assertSame(['clinvar:15175'], array_map(static fn ($e) => (string) $e->id, $last->items));
        self::assertSame('Likely benign', $last->first()->value('classification'));
        self::assertNull($last->next);

        $none = $source->search(new Query('zzqqxx'));
        self::assertCount(0, $none);
        self::assertSame(0, $none->total);
        self::assertCount(0, $source->search(new Query('rs334', Domain::CHEMISTRY)));
        self::assertCount(5, $this->calls, 'no summary for nothing found');
    }

    public function testTheKeyAndItsThrottle(): void
    {
        $this->source(null, ['api_key' => 'k3y'])->search(new Query('zzqqxx'));
        self::assertSame('k3y', $this->calls[0]['api_key']);
    }

    public function testA429IsMadeAgainOnce(): void
    {
        $answers = [new MockResponse('{"error":"API rate limit exceeded"}', ['http_code' => 429, 'response_headers' => ['Retry-After: 1']]), new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/esummary-15333.json'))];
        $source = (new ClinVarSourceFactory(new MockHttpClient(static function () use (&$answers): MockResponse {
            return array_shift($answers);
        })))->create(['throttle' => 0]);

        self::assertSame('clinvar:15333', (string) $source->entity('15333')?->id, 'NCBI\'s wait, then the call again');

        $twice = [new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 1']]), new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 1']])];
        $source = (new ClinVarSourceFactory(new MockHttpClient(static function () use (&$twice): MockResponse {
            return array_shift($twice);
        })))->create(['throttle' => 0]);
        $this->expectException(\Omnilab\Exception\RateLimitedException::class);
        $source->entity('15333');
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{"error":"API key invalid"}', ['http_code' => 400]))->entity('15333');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('API key invalid', $e->getMessage());
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 502]))->entity('15333');
    }

    public function testTheFactory(): void
    {
        $factory = new ClinVarSourceFactory(new MockHttpClient());

        self::assertSame('clinvar', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $factory->create()->capabilities());
    }
}
