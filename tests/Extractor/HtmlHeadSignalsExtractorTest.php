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

    public function testSeparatesHreflangLinksFoundOutsideHead(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="alternate" hreflang="fr" href="https://example.com/fr">
                <link rel="Alternate" hreflang="en-GB" href="/en">
                <link rel="alternate" type="application/rss+xml" href="/feed.xml">
            </head><body>
                <link rel="alternate" hreflang="de" href="https://example.com/de">
            </body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertCount(2, $signals->hreflangLinks);
        $this->assertCount(1, $signals->bodyHreflangLinks);
        $this->assertSame('de', $signals->bodyHreflangLinks[0]->hreflang);
        $this->assertSame('fr', $signals->hreflangLinks[0]->hreflang);
        $this->assertSame('https://example.com/fr', $signals->hreflangLinks[0]->href);
        $this->assertSame('en-GB', $signals->hreflangLinks[1]->hreflang);
        $this->assertSame('/en', $signals->hreflangLinks[1]->href);
        $this->assertSame(
            [
                'https://example.com/fr' => 'https://example.com/fr',
                'https://example.com/en' => 'https://example.com/en',
            ],
            $signals->hreflangUrls('https://example.com/fr'),
        );
    }

    public function testAnInvalidElementInHeadPushesTheLinksAfterItOutOfHead(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html><head>
                <title>T</title>
                <link rel="alternate" hreflang="fr" href="https://example.com/fr">
                <div>A tracking snippet pasted in the wrong place</div>
                <link rel="canonical" href="https://example.com/fr">
                <link rel="alternate" hreflang="en" href="https://example.com/en">
            </head><body></body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame(['fr'], array_map(static fn($link): string => $link->hreflang, $signals->hreflangLinks));
        $this->assertSame(['en'], array_map(static fn($link): string => $link->hreflang, $signals->bodyHreflangLinks));
        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/fr'], $signals->bodyCanonicalHrefs);
    }

    /**
     * @dataProvider headBreakerProvider
     */
    public function testAStrayElementInHeadPushesTheCanonicalAfterItOutOfHead(string $breaker): void
    {
        $signals = (new HtmlHeadSignalsExtractor())->extract(self::pageWithHead($breaker));

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/probe'], $signals->bodyCanonicalHrefs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headBreakerProvider(): iterable
    {
        yield 'div' => ['<div>stray</div>'];
        yield 'tracking pixel' => ['<img src="https://t.example/p.gif" alt="">'];
        yield 'stray text' => ['oops'];
        yield 'misplaced tag manager iframe' => ['<iframe src="https://gtm.example/ns.html"></iframe>'];
    }

    /**
     * @dataProvider validHeadContentProvider
     */
    public function testValidHeadContentKeepsTheCanonicalInHead(string $content): void
    {
        $signals = (new HtmlHeadSignalsExtractor())->extract(self::pageWithHead($content));

        $this->assertSame(['https://example.com/probe'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHeadContentProvider(): iterable
    {
        yield 'meta, base, script and style' => [
            '<meta charset="utf-8"><base href="/"><script>var a = "</div>";</script><style>p{}</style>',
        ];
        yield 'JSON-LD' => ['<script type="application/ld+json">{"a": "<div>"}</script>'];
        yield 'template' => ['<template><div>in template</div></template>'];
        yield 'comment' => ['<!-- a comment -->'];
        // With JavaScript enabled, as a rendering crawler reads the page, <noscript> content is plain text.
        yield 'noscript holding a tracking pixel' => [
            '<noscript><img src="https://t.example/p.gif" alt=""></noscript>',
        ];
    }

    public function testIgnoresSignalsInsideTemplate(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr"><head>
                <template>
                    <link rel="canonical" href="https://example.com/template">
                    <link rel="alternate" hreflang="de" href="https://example.com/de">
                    <meta name="robots" content="noindex">
                    <meta http-equiv="refresh" content="0; url=https://example.com/elsewhere">
                </template>
                <link rel="canonical" href="https://example.com/real">
            </head><body></body></html>
            HTML;

        $signals = (new HtmlHeadSignalsExtractor())->extract($html);

        $this->assertSame(['https://example.com/real'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
        $this->assertSame([], $signals->hreflangLinks);
        $this->assertSame([], $signals->bodyHreflangLinks);
        $this->assertSame([], $signals->metaRobots);
        $this->assertNull($signals->metaRefreshUrl);
        $this->assertSame('fr', $signals->htmlLang);
    }

    public function testKeepsNonAsciiUrlsIntact(): void
    {
        $signals = (new HtmlHeadSignalsExtractor())->extract(
            '<!DOCTYPE html><html><head><link rel="canonical" href="https://example.com/café"></head>'
            .'<body></body></html>'
        );

        $this->assertSame(['https://example.com/café'], $signals->canonicalHrefs);
    }

    private static function pageWithHead(string $content): string
    {
        return '<!DOCTYPE html><html><head><title>T</title>'.$content
            .'<link rel="canonical" href="https://example.com/probe"></head><body><p>content</p></body></html>';
    }
}
