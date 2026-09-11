<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Crawler;

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

    private function crawler(
        HttpClientInterface $httpClient,
        int $maxDepth = 3,
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
