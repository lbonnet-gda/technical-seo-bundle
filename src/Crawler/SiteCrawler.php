<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Crawler;

use Lbonnet\CrawlerToolkit\Http\BoundedContentReader;
use Lbonnet\CrawlerToolkit\Http\SiteThrottleExemption;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtCheckerInterface;
use Lbonnet\TechnicalSeoBundle\Auditor\PageAuditorInterface;
use Lbonnet\TechnicalSeoBundle\Auditor\SiteAuditorInterface;
use Lbonnet\TechnicalSeoBundle\Event\CrawlCompletedEvent;
use Lbonnet\TechnicalSeoBundle\Extractor\HeadSignalsExtractorInterface;
use Lbonnet\TechnicalSeoBundle\Extractor\InternalLinkExtractorInterface;
use Lbonnet\TechnicalSeoBundle\Http\HeaderFetcher;
use Lbonnet\TechnicalSeoBundle\Http\RedirectChainResolverInterface;
use Lbonnet\TechnicalSeoBundle\Model\CrawlContext;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Lbonnet\TechnicalSeoBundle\Model\RedirectChain;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Unlike a plain content crawler, this one never lets the HTTP client follow redirects: a
 * 3xx is a finding, not a detour. Every page is requested with "max_redirects" => 0, and
 * chains are walked explicitly so each hop stays visible.
 */
final class SiteCrawler implements CrawlerInterface
{
    private const MAX_HTML_LENGTH = 5_000_000;

    public const DEFAULT_USER_AGENT = HeaderFetcher::DEFAULT_USER_AGENT;

    public function __construct(
        private readonly InternalLinkExtractorInterface $linkExtractor,
        private readonly HeadSignalsExtractorInterface $signalsExtractor,
        private readonly PageAuditorInterface $pageAuditor,
        private readonly SiteAuditorInterface $siteAuditor,
        private readonly RedirectChainResolverInterface $redirectChainResolver,
        private readonly HttpClientInterface $httpClient,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        private readonly int $defaultMaxDepth = 3,
        private readonly int $defaultTimeout = 10,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        /** @var list<string> */
        private readonly array $defaultExcludePatterns = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function crawl(
        string $startUrl,
        ?int $maxDepth = null,
        array $excludePatterns = [],
        ?callable $progressCallback = null,
    ): TechnicalSeoReport {
        $startTime = microtime(true);
        $maxDepth = $maxDepth ?? $this->defaultMaxDepth;
        $activeExcludePatterns = array_merge($this->defaultExcludePatterns, $excludePatterns);

        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<string, PageResponse> $responses */
        $responses = [];
        /** @var array<string, RedirectChain> $chains */
        $chains = [];
        /** @var array<string, list<string>> $referrers */
        $referrers = [];
        /** @var list<PageAudit> $pages */
        $pages = [];
        $totalChecked = 0;

        /** @var list<array{url: string, depth: int}> $queue */
        $queue = [['url' => $startUrl, 'depth' => 0]];

        $startKey = UrlResolver::dedupKey($startUrl);
        $siteHost = self::hostOf($startUrl);
        $throttleExemption = SiteThrottleExemption::begin($this->httpClient, $startUrl, $this->robotsTxtChecker);

        try {
            while (!empty($queue)) {
                $item = array_shift($queue);
                $url = $item['url'];
                $depth = $item['depth'];

                $key = UrlResolver::dedupKey($url);

                if (isset($visited[$key])) {
                    continue;
                }

                $visited[$key] = true;
                $page = $this->fetch($url, $depth);

                if ($page === null) {
                    continue;
                }

                $responses[$key] = $page;

                if ($page->isRedirect()) {
                    $chain = $this->redirectChainResolver->resolve($page);
                    $chains[$key] = $chain;

                    $finalUrl = $chain->finalUrl;

                    if ($key === $startKey && $finalUrl !== null && $chain->endsSuccessfully()) {
                        $finalHost = self::hostOf($finalUrl);

                        if ($finalHost !== null && strcasecmp($finalHost, (string)$siteHost) !== 0) {
                            $siteHost = $finalHost;
                            $throttleExemption->moveTo($finalUrl);
                        }
                    }

                    if (
                        $finalUrl !== null
                        && !$chain->isLoop
                        && $chain->finalStatusCode !== null
                        && !isset($visited[UrlResolver::dedupKey($finalUrl)])
                        && $this->isCrawlable($finalUrl, $siteHost, $activeExcludePatterns)
                    ) {
                        $queue[] = ['url' => $finalUrl, 'depth' => $depth];
                    }

                    continue;
                }

                if ($page->html === null) {
                    continue;
                }

                $signals = $this->signalsExtractor->extract($page->html);
                $issues = $this->pageAuditor->audit($page, $signals);

                $totalChecked++;
                $pages[] = new PageAudit(
                    url: $url,
                    statusCode: $page->statusCode,
                    issues: $issues,
                    signals: $signals,
                    depth: $depth,
                );

                if ($progressCallback !== null) {
                    $progressCallback($url, $totalChecked, count($issues));
                }

                if ($depth >= $maxDepth) {
                    continue;
                }

                foreach ($this->linkExtractor->extract($page->html, $url, $activeExcludePatterns) as $link) {
                    if ($link->isExternal) {
                        continue;
                    }

                    $linkKey = UrlResolver::dedupKey($link->url);

                    $referrers[$linkKey][] = $url;

                    if (isset($visited[$linkKey]) || $this->robotsTxtChecker?->isAllowed($link->url) === false) {
                        continue;
                    }

                    $queue[] = ['url' => $link->url, 'depth' => $depth + 1];
                }
            }
        } finally {
            $throttleExemption->end();
        }

        $pages = $this->siteAuditor->audit($pages, new CrawlContext($responses, $chains, $referrers));

        $report = new TechnicalSeoReport(
            startUrl: $startUrl,
            pages: $pages,
            totalChecked: $totalChecked,
            totalDuration: round(microtime(true) - $startTime, 3),
        );

        try {
            $this->eventDispatcher?->dispatch(new CrawlCompletedEvent($report));
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('[TechnicalSeo] A "CrawlCompletedEvent" listener failed: %s', $e->getMessage())
            );
        }

        return $report;
    }

    private function fetch(string $url, int $depth): ?PageResponse
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                'timeout' => $this->defaultTimeout,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            /** @var array<string, list<string>> $headers */
            $headers = $response->getHeaders(false);
            $redirectLocation = $response->getInfo('redirect_url');

            $page = new PageResponse(
                url: $url,
                statusCode: $statusCode,
                headers: $headers,
                depth: $depth,
                redirectLocation: is_string($redirectLocation) ? $redirectLocation : null,
            );

            if (!$page->isSuccessful() || !$page->isHtml()) {
                $response->cancel();

                return $page;
            }

            return $page->withHtml(
                BoundedContentReader::read($this->httpClient, $response, self::MAX_HTML_LENGTH)
            );
        } catch (Throwable $e) {
            $this->logger->debug(sprintf('[TechnicalSeo] Could not fetch "%s": %s', $url, $e->getMessage()));

            return null;
        }
    }

    /**
     * @param list<string> $excludePatterns
     */
    private function isCrawlable(string $url, ?string $siteHost, array $excludePatterns): bool
    {
        return $this->isInternal($url, $siteHost)
            && !$this->isExcluded($url, $excludePatterns)
            && $this->robotsTxtChecker?->isAllowed($url) !== false;
    }

    private function isInternal(string $url, ?string $siteHost): bool
    {
        $host = self::hostOf($url);

        return $siteHost !== null && $host !== null && strcasecmp($host, $siteHost) === 0;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    /**
     * @param list<string> $patterns
     */
    private function isExcluded(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
