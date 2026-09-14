<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Model;

use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\HreflangLink;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PageAuditTest extends TestCase
{
    public function testResolvesItsSignalsAgainstItsOwnUrl(): void
    {
        $page = new PageAudit(
            url: 'https://example.com/section/a?filter=1',
            statusCode: Response::HTTP_OK,
            signals: new HeadSignals(
                canonicalHrefs: ['../b'],
                hreflangLinks: [new HreflangLink('en', '/en/b')],
            ),
        );

        $this->assertSame('https://example.com/b', $page->canonicalElsewhere());
        $this->assertSame(['https://example.com/en/b' => 'https://example.com/en/b'], $page->hreflangUrls());
        $this->assertTrue($page->isCanonicalizedVariant());
    }

    public function testAPageWithoutSignalsHasNoCanonicalNorHreflang(): void
    {
        $page = new PageAudit(url: 'https://example.com/old', statusCode: Response::HTTP_MOVED_PERMANENTLY);

        $this->assertNull($page->canonicalElsewhere());
        $this->assertSame([], $page->hreflangUrls());
        $this->assertFalse($page->isCanonicalizedVariant());
    }
}
