<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Http;

use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Lbonnet\TechnicalSeoBundle\Model\RedirectChain;
use Lbonnet\TechnicalSeoBundle\Model\RedirectHop;
use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class RedirectChainResolver implements RedirectChainResolverInterface
{
    public function __construct(
        private readonly HeaderFetcher $headerFetcher,
        private readonly int $maxFollowedHops = 10,
    ) {
    }

    public function resolve(PageResponse $response): RedirectChain
    {
        $startUrl = $response->url;
        $startStatusCode = $response->statusCode;
        /** @var list<RedirectHop> $hops */
        $hops = [];
        $seen = [UrlResolver::dedupKey($startUrl) => true];
        $current = $response;

        while (true) {
            $location = $this->nextLocation($current);

            if ($location === null) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $current->url, $current->statusCode);
            }

            $hops[] = new RedirectHop($current->url, $current->statusCode, $location);
            $key = UrlResolver::dedupKey($location);

            if (isset($seen[$key])) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, null, isLoop: true);
            }

            $seen[$key] = true;

            if (count($hops) >= $this->maxFollowedHops) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, null, truncated: true);
            }

            $next = $this->headerFetcher->fetch($location);

            if ($next === null) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location);
            }

            if (!$next->isRedirect()) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, $next->statusCode);
            }

            $current = $next;
        }
    }

    private function nextLocation(PageResponse $response): ?string
    {
        if ($response->redirectLocation !== null && UrlResolver::isAbsoluteHttpUrl($response->redirectLocation)) {
            return $response->redirectLocation;
        }

        $location = $response->headers['location'][0] ?? null;

        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        return UrlResolver::resolve($response->url, $location);
    }
}
