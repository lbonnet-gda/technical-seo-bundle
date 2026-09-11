<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Http;

use Lbonnet\TechnicalSeoBundle\Model\PageResponse;

interface TargetProbeInterface
{
    /**
     * Fetches a URL that was not part of the crawl without following redirects. Returns null
     * when probing is disabled, the budget is exhausted, or the request failed — in which case
     * the caller must not report anything about that URL.
     */
    public function probe(string $url): ?PageResponse;

    /**
     * Clears the cache and the probe budget. Called at the start of every crawl, since the
     * probe is a long-lived service in a worker process.
     */
    public function reset(): void;
}
