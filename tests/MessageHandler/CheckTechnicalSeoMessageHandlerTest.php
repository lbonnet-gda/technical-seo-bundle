<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\MessageHandler;

use Lbonnet\TechnicalSeoBundle\Crawler\CrawlerInterface;
use Lbonnet\TechnicalSeoBundle\Message\CheckTechnicalSeoMessage;
use Lbonnet\TechnicalSeoBundle\MessageHandler\CheckTechnicalSeoMessageHandler;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use PHPUnit\Framework\TestCase;

final class CheckTechnicalSeoMessageHandlerTest extends TestCase
{
    public function testItCrawlsTheUrlFromTheMessage(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->once())
            ->method('crawl')
            ->with('https://example.com/blog', 2, ['#/preview#'], null, 50)
            ->willReturn(new TechnicalSeoReport('https://example.com/blog'));

        $handler = new CheckTechnicalSeoMessageHandler($crawler, defaultBaseUrl: 'https://example.com');

        $handler(new CheckTechnicalSeoMessage('https://example.com/blog', 2, ['#/preview#'], 50));
    }

    public function testItFallsBackToTheConfiguredBaseUrl(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->once())
            ->method('crawl')
            ->with('https://example.com', null, [])
            ->willReturn(new TechnicalSeoReport('https://example.com'));

        $handler = new CheckTechnicalSeoMessageHandler($crawler, defaultBaseUrl: 'https://example.com');

        $handler(new CheckTechnicalSeoMessage());
    }

    public function testItDoesNothingWithoutAnyUrl(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->never())->method('crawl');

        (new CheckTechnicalSeoMessageHandler($crawler))(new CheckTechnicalSeoMessage());
    }
}
