<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Http;

use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class HttpTargetProbe implements TargetProbeInterface
{
    /** @var array<string, PageResponse|null> dedup key => response (null = probed and failed) */
    private array $cache = [];

    private int $probesUsed = 0;

    public function __construct(
        private readonly HeaderFetcher $headerFetcher,
        private readonly bool $enabled = true,
        private readonly int $maxProbes = 200,
    ) {
    }

    public function probe(string $url): ?PageResponse
    {
        if (!$this->enabled || !UrlResolver::isAbsoluteHttpUrl($url)) {
            return null;
        }

        $key = UrlResolver::dedupKey($url);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->maxProbes > 0 && $this->probesUsed >= $this->maxProbes) {
            return null;
        }

        $this->probesUsed++;

        return $this->cache[$key] = $this->headerFetcher->fetch($url);
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->probesUsed = 0;
    }
}
