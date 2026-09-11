<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Crawler;

use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;

interface CrawlerInterface
{
    /**
     * Crawls a site from its starting URL and audits the technical SEO signals of every
     * internal HTML page found.
     *
     * @param string $startUrl Starting URL
     * @param int|null $maxDepth Max depth (null = bundle default value)
     * @param list<string> $excludePatterns Additional exclusion regex patterns
     * @param (callable(string $currentUrl, int $totalChecked, int $issuesCount): void)|null $progressCallback
     */
    public function crawl(
        string $startUrl,
        ?int $maxDepth = null,
        array $excludePatterns = [],
        ?callable $progressCallback = null,
    ): TechnicalSeoReport;
}
