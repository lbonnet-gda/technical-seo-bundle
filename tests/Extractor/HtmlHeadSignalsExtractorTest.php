<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Extractor;

use Lbonnet\TechnicalSeoBundle\Extractor\HtmlHeadSignalsExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlHeadSignalsExtractorTest extends TestCase
{
    public function testExtractsCanonicalRobotsAndLang(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
                <head>
                    <title>Accueil</title>
                    <link rel="stylesheet" href="/app.css">
                    <link rel="Canonical" href="https://example.com/accueil">
                    <meta name="Robots" content="noindex, follow">
                </head>
                <body><p>Bonjour</p></body>
            </html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame(['https://example.com/accueil'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
        $this->assertSame(['noindex, follow'], $signals->metaRobots);
        $this->assertSame('fr', $signals->htmlLang);
        $this->assertNull($signals->metaRefreshUrl);
        $this->assertSame('https://example.com/accueil', $signals->effectiveCanonicalHref());
        $this->assertTrue($signals->metaRobotsDirectives()->hasNoindex());
    }

    public function testDetectsSeveralConflictingCanonicals(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="canonical" href="https://example.com/a">
                <link rel="canonical" href="https://example.com/b">
            </head><body></body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $signals->canonicalHrefs);
        $this->assertNull($signals->effectiveCanonicalHref());
    }

    public function testRepeatingTheSameCanonicalIsNotAConflict(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="canonical" href="https://example.com/a">
                <link rel="canonical" href="https://example.com/a">
            </head><body></body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame('https://example.com/a', $signals->effectiveCanonicalHref());
    }

    public function testSeparatesCanonicalsFoundOutsideHead(): void
    {
        $html = <<<HTML
            <html><head><title>T</title></head>
            <body>
                <div><link rel="canonical" href="https://example.com/a"></div>
            </body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/a'], $signals->bodyCanonicalHrefs);
    }

    public function testExtractsMetaRefreshTarget(): void
    {
        $html = <<<HTML
            <html><head>
                <meta http-equiv="refresh" content="0; url=https://example.com/new">
            </head><body></body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame('https://example.com/new', $signals->metaRefreshUrl);
    }

    public function testIgnoresMetaRefreshWithoutTarget(): void
    {
        $html = '<html><head><meta http-equiv="refresh" content="30"></head><body></body></html>';

        $this->assertNull((new HtmlHeadSignalsExtractor())->extract($html)->metaRefreshUrl);
    }

    public function testReturnsEmptySignalsForEmptyHtml(): void
    {
        $signals = (new HtmlHeadSignalsExtractor())->extract('   ');

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertNull($signals->htmlLang);
    }

    public function testMissingLangAttributeIsNull(): void
    {
        $html = '<html><head><title>T</title></head><body></body></html>';

        $this->assertNull((new HtmlHeadSignalsExtractor())->extract($html)->htmlLang);
    }

    public function testKeepsCanonicalWithEmptyHref(): void
    {
        $html = '<html><head><link rel="canonical" href=""></head><body></body></html>';

        $this->assertSame([''], (new HtmlHeadSignalsExtractor())->extract($html)->canonicalHrefs);
    }
}
