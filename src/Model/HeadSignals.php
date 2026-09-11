<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

final class HeadSignals
{
    /**
     * @param list<string> $canonicalHrefs raw href of every <link rel="canonical"> inside <head>
     * @param list<string> $bodyCanonicalHrefs same, for tags found outside <head> (which search engines ignore)
     * @param list<string> $metaRobots raw content of every <meta name="robots"> tag
     */
    public function __construct(
        public readonly array $canonicalHrefs = [],
        public readonly array $bodyCanonicalHrefs = [],
        public readonly array $metaRobots = [],
        public readonly ?string $metaRefreshUrl = null,
        public readonly ?string $htmlLang = null,
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
}
