<?php

namespace Omnilab\ClinVar\Tests;

use Omnilab\ClinVar\ClinVarSourceFactory;
use Omnilab\Source\HttpSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** E-utilities' figure as ClinVar's policy: 3 a second, 10 with a key. */
final class PolicyTest extends TestCase
{
    public function testAKeyRaisesTheFigure(): void
    {
        $keyless = (new ClinVarSourceFactory(new MockHttpClient()))->create();
        \assert($keyless instanceof HttpSource);
        self::assertSame(3, $keyless->guard()->remaining(HttpSource::REQUEST));

        $keyed = (new ClinVarSourceFactory(new MockHttpClient()))->create(['api_key' => 'test-key']);
        \assert($keyed instanceof HttpSource);
        self::assertSame(10, $keyed->guard()->remaining(HttpSource::REQUEST));
    }
}
