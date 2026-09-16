<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Crawler;

use Lbonnet\CrawlerToolkit\Http\ThrottleExemptionInterface;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtChecker;
use Lbonnet\TechnicalSeoBundle\Auditor\PageAuditor;
use Lbonnet\TechnicalSeoBundle\Auditor\SiteAuditor;
use Lbonnet\TechnicalSeoBundle\Crawler\SiteCrawler;
use Lbonnet\TechnicalSeoBundle\Event\CrawlCompletedEvent;
use Lbonnet\TechnicalSeoBundle\Extractor\HtmlHeadSignalsExtractor;
use Lbonnet\TechnicalSeoBundle\Extractor\HtmlInternalLinkExtractor;
use Lbonnet\TechnicalSeoBundle\Http\HeaderFetcher;
use Lbonnet\TechnicalSeoBundle\Http\RedirectChainResolver;
use Lbonnet\TechnicalSeoBundle\Http\TargetProbeInterface;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SiteCrawlerTest extends TestCase
{
    public function testAuditsInternalPagesAndIgnoresExternalOnes(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html(
                'https://example.com/',
                '<a href="/page-1">Page 1</a><a href="https://other.example.org/">Elsewhere</a>',
            ),
            'https://example.com/page-1' => self::html('https://example.com/page-1'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(2, $report->totalChecked);
        $this->assertSame(
            ['https://example.com/', 'https://example.com/page-1'],
            array_map(static fn(PageAudit $page): string => $page->url, $report->pages),
        );
        $this->assertFalse($report->hasIssues());
    }

    public function testFollowsARedirectAndAuditsItsTarget(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::redirect('https://example.com/home'),
            'https://example.com/home' => self::html('https://example.com/home'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
        $this->assertSame('https://example.com/home', $report->pages[0]->url);
        // A single redirect on the start URL is not a finding by itself.
        $this->assertFalse($report->hasIssues());
    }

    public function testFlagsAnInternalLinkPointingToARedirect(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/old">Old</a>'),
            'https://example.com/old' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::html('https://example.com/new'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(2, $report->totalChecked);
        $this->assertSame(
            [IssueType::InternalLinkToRedirect],
            self::types($report->pages[0]->issues),
        );
        $this->assertSame('https://example.com/new', $report->pages[1]->url);
    }

    public function testFlagsACanonicalPointingToAPageThatRedirects(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html(
                'https://example.com/old',
                '<a href="/old">Old</a>',
            ),
            'https://example.com/old' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::html('https://example.com/new'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertContains(IssueType::CanonicalTargetRedirects, self::types($report->pages[0]->issues));
    }

    public function testFlagsANoindexPageReachedThroughALink(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/hidden">Hidden</a>'),
            'https://example.com/hidden' => self::html(
                'https://example.com/hidden',
                '',
                '<meta name="robots" content="noindex">',
            ),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($report->pages[1]->issues));
    }

    public function testDoesNotAuditNonHtmlResponses(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/doc.pdf">Doc</a>'),
            'https://example.com/doc.pdf' => [
                '%PDF-1.4',
                [
                    'response_headers' => ['content-type' => 'application/pdf'],
                ],
            ],
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
    }

    public function testStopsAtTheConfiguredDepth(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/one">1</a>'),
            'https://example.com/one' => self::html('https://example.com/one', '<a href="/two">2</a>'),
            'https://example.com/two' => self::html('https://example.com/two'),
        ]);

        $report = $this->crawler($httpClient, maxDepth: 1)->crawl('https://example.com/');

        $this->assertSame(2, $report->totalChecked);
    }

    public function testStopsAtTheMaxPagesLimitAndMarksTheReportAsTruncated(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/one">1</a><a href="/two">2</a>'),
            'https://example.com/one' => self::html('https://example.com/one'),
            'https://example.com/two' => self::html('https://example.com/two'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/', maxPages: 2);

        $this->assertSame(2, $report->totalChecked);
        $this->assertTrue($report->truncated);
        $this->assertSame(['https://example.com/', 'https://example.com/one'], array_column($report->pages, 'url'));
    }

    public function testAReportIsNotTruncatedWhenTheSiteFitsTheLimitOrThereIsNone(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/one">1</a><a href="/">home</a>'),
            'https://example.com/one' => self::html('https://example.com/one', '<a href="/">home</a>'),
        ]);

        foreach ([2, 0] as $maxPages) {
            $report = $this->crawler($httpClient, maxPages: 1)->crawl('https://example.com/', maxPages: $maxPages);

            $this->assertSame(2, $report->totalChecked, (string)$maxPages);
            $this->assertFalse($report->truncated, (string)$maxPages);
        }

        $this->assertTrue($this->crawler($httpClient, maxPages: 1)->crawl('https://example.com/')->truncated);
    }

    public function testDispatchesTheCompletedEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CrawlCompletedEvent::class));

        $httpClient = $this->siteWith(['https://example.com/' => self::html('https://example.com/')]);

        $this->crawler($httpClient, dispatcher: $dispatcher)->crawl('https://example.com/');
    }

    public function testReturnsTheReportEvenIfAListenerThrows(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('Slack transport misconfigured'));

        $httpClient = $this->siteWith(['https://example.com/' => self::html('https://example.com/')]);

        $report = $this->crawler($httpClient, dispatcher: $dispatcher)->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
    }

    public function testReportsABrokenTemporaryRedirectOnceOnTheRedirectItself(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/old">Old</a>'),
            'https://example.com/old' => [
                '',
                [
                    'http_code' => Response::HTTP_FOUND,
                    'response_headers' => ['location' => 'https://example.com/gone'],
                ],
            ],
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($report->pages[0]->issues));
        $this->assertSame('https://example.com/old', $report->pages[1]->url);
        $this->assertSame(
            [IssueType::TemporaryRedirect, IssueType::RedirectToError],
            self::types($report->pages[1]->issues),
        );
    }

    public function testFollowsTheSiteWhenTheStartUrlRedirectsToAnotherHost(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::redirect('https://www.example.com/'),
            'https://www.example.com/' => self::redirect('https://www.example.com/fr'),
            'https://www.example.com/fr' => self::html('https://www.example.com/fr', '<a href="/fr/page">Page</a>'),
            'https://www.example.com/fr/page' => self::html('https://www.example.com/fr/page'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com');

        $this->assertSame(2, $report->totalChecked);
        $this->assertSame(
            ['https://www.example.com/fr', 'https://www.example.com/fr/page', 'https://example.com'],
            array_map(static fn(PageAudit $page): string => $page->url, $report->pages),
        );
        $this->assertSame([IssueType::RedirectChainTooLong], self::types($report->pages[2]->issues));
    }

    public function testDoesNotFollowAnInternalLinkThatRedirectsToAnotherHost(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/', '<a href="/partner">Partner</a>'),
            'https://example.com/partner' => self::redirect('https://partner.example.org/'),
            'https://partner.example.org/' => self::html('https://partner.example.org/', '<a href="/deep">Deep</a>'),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
        $this->assertSame('https://example.com/', $report->pages[0]->url);
    }

    public function testMovesTheThrottleExemptionToWhereTheStartUrlRedirects(): void
    {
        $httpClient = new class($this->siteWith([
            'https://example.com/' => self::redirect('https://www.example.com/'),
            'https://www.example.com/' => self::html('https://www.example.com/'),
        ])) implements HttpClientInterface, ThrottleExemptionInterface {
            /** @var list<array{0: ?string, 1: int}> */
            public array $hostDelayCalls = [];

            public function __construct(private HttpClientInterface $inner)
            {
            }

            public function setHostDelay(?string $host, int $delayMs = 0): void
            {
                $this->hostDelayCalls[] = [$host, $delayMs];
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->inner->request($method, $url, $options);
            }

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null
            ): ResponseStreamInterface {
                return $this->inner->stream($responses, $timeout);
            }

            public function withOptions(array $options): static
            {
                $clone = clone $this;
                $clone->inner = $this->inner->withOptions($options);

                return $clone;
            }
        };

        $this->crawler($httpClient)->crawl('https://example.com');

        $this->assertSame(
            [['example.com', 0], ['www.example.com', 0], [null, 0]],
            $httpClient->hostDelayCalls,
        );
    }

    public function testFlagsAnHreflangAlternateThatDoesNotLinkBack(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/fr' => self::html(
                'https://example.com/fr',
                '<a href="/en">English</a>',
                '<link rel="alternate" hreflang="fr" href="https://example.com/fr">'
                .'<link rel="alternate" hreflang="en" href="https://example.com/en">'
                .'<link rel="alternate" hreflang="x-default" href="https://example.com/fr">',
            ),
            'https://example.com/en' => self::html(
                'https://example.com/en',
                '',
                '<link rel="alternate" hreflang="en" href="https://example.com/en">'
                .'<link rel="alternate" hreflang="x-default" href="https://example.com/en">',
            ),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/fr');

        $this->assertSame(2, $report->totalChecked);
        $this->assertSame([IssueType::HreflangNotReciprocal], self::types($report->pages[0]->issues));
        $this->assertSame([], $report->pages[1]->issues);
    }

    public function testAFilteredVariantOfAMultilingualPageRaisesNoHreflangIssue(): void
    {
        $cluster = '<link rel="alternate" hreflang="en" href="https://example.com/en/presse">'
            .'<link rel="alternate" hreflang="fr" href="https://example.com/fr/presse">'
            .'<link rel="alternate" hreflang="x-default" href="https://example.com/en/presse">';

        $httpClient = $this->siteWith([
            'https://example.com/en/presse' => self::html(
                'https://example.com/en/presse',
                '<a href="/en/presse?categoryId=5">Category</a><a href="/fr/presse">Français</a>',
                $cluster,
            ),
            'https://example.com/en/presse?categoryId=5' => self::html('https://example.com/en/presse', '', $cluster),
            'https://example.com/fr/presse' => self::html('https://example.com/fr/presse', '', $cluster),
        ]);

        $report = $this->crawler($httpClient)->crawl('https://example.com/en/presse');

        $this->assertSame(3, $report->totalChecked);
        $this->assertFalse($report->hasIssues());
    }

    public function testReportsARobotsTxtServerErrorOnItsOwnEntry(): void
    {
        $httpClient = $this->siteWith([
            'https://example.com/' => self::html('https://example.com/'),
            'https://example.com/robots.txt' => ['', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]],
        ]);
        $robotsTxtChecker = new RobotsTxtChecker($httpClient, SiteCrawler::DEFAULT_USER_AGENT);

        $crawler = new SiteCrawler(
            new HtmlInternalLinkExtractor(),
            new HtmlHeadSignalsExtractor(),
            new PageAuditor(),
            new SiteAuditor($this->createMock(TargetProbeInterface::class), robotsTxtProvider: $robotsTxtChecker),
            new RedirectChainResolver(new HeaderFetcher($httpClient)),
            $httpClient,
            robotsTxtChecker: $robotsTxtChecker,
        );

        $report = $crawler->crawl('https://example.com/');

        $this->assertSame(1, $report->totalChecked);
        $this->assertSame([], $report->pages[0]->issues);
        $this->assertSame('https://example.com/robots.txt', $report->pages[1]->url);
        $this->assertSame([IssueType::RobotsTxtServerError], self::types($report->pages[1]->issues));
    }

    private function crawler(
        HttpClientInterface $httpClient,
        int $maxDepth = 3,
        int $maxPages = 500,
        ?EventDispatcherInterface $dispatcher = null,
    ): SiteCrawler {
        return new SiteCrawler(
            new HtmlInternalLinkExtractor(),
            new HtmlHeadSignalsExtractor(),
            new PageAuditor(),
            new SiteAuditor($this->createMock(TargetProbeInterface::class)),
            new RedirectChainResolver(new HeaderFetcher($httpClient)),
            $httpClient,
            eventDispatcher: $dispatcher,
            defaultMaxDepth: $maxDepth,
            defaultMaxPages: $maxPages,
        );
    }

    /**
     * A MockResponse can only be consumed once, and a redirect target is requested twice (once
     * to resolve the chain, once to read its markup), so every request gets a fresh instance.
     *
     * @param array<string, array{string, array<string, mixed>}> $responses URL => [body, info]
     */
    private function siteWith(array $responses): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($responses): MockResponse {
            if (!isset($responses[$url])) {
                return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
            }

            [$body, $info] = $responses[$url];

            return new MockResponse($body, $info);
        });
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function html(string $canonical, string $body = '', string $extraHead = ''): array
    {
        $html = sprintf(
            '<!DOCTYPE html><html lang="fr"><head><title>T</title>'
            .'<link rel="canonical" href="%s">%s</head><body>%s</body></html>',
            $canonical,
            $extraHead,
            $body,
        );

        return [$html, ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location): array
    {
        return [
            '',
            [
                'http_code' => Response::HTTP_MOVED_PERMANENTLY,
                'response_headers' => ['location' => $location],
            ],
        ];
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<IssueType>
     */
    private static function types(array $issues): array
    {
        return array_map(static fn(Issue $issue): IssueType => $issue->type, $issues);
    }
}
