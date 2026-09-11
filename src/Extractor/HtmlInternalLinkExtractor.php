<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Extractor;

use Lbonnet\CrawlerToolkit\Html\DiscoveredHref;
use Lbonnet\CrawlerToolkit\Html\LinkDiscoverer;
use Lbonnet\TechnicalSeoBundle\Model\DiscoveredLink;

final class HtmlInternalLinkExtractor implements InternalLinkExtractorInterface
{
    public function __construct(
        private readonly LinkDiscoverer $discoverer = new LinkDiscoverer(),
    ) {
    }

    public function extract(string $html, string $sourceUrl, array $excludePatterns = []): array
    {
        return array_map(
            static fn(DiscoveredHref $href): DiscoveredLink => new DiscoveredLink(
                url: $href->url,
                isExternal: $href->isExternal,
            ),
            $this->discoverer->discover($html, $sourceUrl, $excludePatterns),
        );
    }
}
