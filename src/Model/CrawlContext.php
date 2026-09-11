<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class CrawlContext
{
    /**
     * @param array<string, PageResponse> $responses dedup key => response
     * @param array<string, RedirectChain> $redirectChains dedup key of the chain's start URL => chain
     * @param array<string, list<string>> $referrers dedup key of a target URL => URLs of the pages linking to it
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $redirectChains = [],
        private readonly array $referrers = [],
    ) {
    }

    public function responseFor(string $url): ?PageResponse
    {
        return $this->responses[UrlResolver::dedupKey($url)] ?? null;
    }

    /**
     * @return array<string, RedirectChain>
     */
    public function redirectChains(): array
    {
        return $this->redirectChains;
    }

    /**
     * @return list<string> URLs of the crawled pages that link to $url
     */
    public function referrersOf(string $url): array
    {
        return $this->referrers[UrlResolver::dedupKey($url)] ?? [];
    }
}
