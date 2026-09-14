<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class HeadSignals
{
    /**
     * @param list<string> $canonicalHrefs raw href of every <link rel="canonical"> inside <head>
     * @param list<string> $bodyCanonicalHrefs same, for tags found outside <head> (which search engines ignore)
     * @param list<string> $metaRobots raw content of every <meta name="robots"> tag
     * @param list<HreflangLink> $hreflangLinks every <link rel="alternate" hreflang> inside <head>
     * @param list<HreflangLink> $bodyHreflangLinks same, for tags found outside <head> (which search engines ignore)
     */
    public function __construct(
        public readonly array $canonicalHrefs = [],
        public readonly array $bodyCanonicalHrefs = [],
        public readonly array $metaRobots = [],
        public readonly ?string $metaRefreshUrl = null,
        public readonly ?string $htmlLang = null,
        public readonly array $hreflangLinks = [],
        public readonly array $bodyHreflangLinks = [],
    ) {
    }

    public function metaRobotsDirectives(): RobotsDirectives
    {
        return RobotsDirectives::parse($this->metaRobots);
    }

    public function effectiveCanonicalHref(): ?string
    {
        $distinct = array_values(array_unique($this->canonicalHrefs));

        return count($distinct) === 1 ? $distinct[0] : null;
    }

    public function canonicalElsewhere(string $pageUrl): ?string
    {
        $href = $this->effectiveCanonicalHref();

        if ($href === null || $href === '') {
            return null;
        }

        $canonical = UrlResolver::resolve($pageUrl, $href);

        if ($canonical === null || UrlResolver::dedupKey($canonical) === UrlResolver::dedupKey($pageUrl)) {
            return null;
        }

        return $canonical;
    }

    /**
     * @return array<string, string> dedup key => URL
     */
    public function hreflangUrls(string $pageUrl): array
    {
        $urls = [];

        foreach ($this->hreflangLinks as $link) {
            $url = UrlResolver::resolve($pageUrl, $link->href);

            if ($url !== null) {
                $urls[UrlResolver::dedupKey($url)] = $url;
            }
        }

        return $urls;
    }

    public function isCanonicalizedVariant(string $pageUrl): bool
    {
        return $this->canonicalElsewhere($pageUrl) !== null
            && !isset($this->hreflangUrls($pageUrl)[UrlResolver::dedupKey($pageUrl)]);
    }
}
