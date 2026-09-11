<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\EventListener;

use Lbonnet\TechnicalSeoBundle\Event\CrawlCompletedEvent;
use Lbonnet\TechnicalSeoBundle\EventListener\StoreReportListener;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use Lbonnet\TechnicalSeoBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoreReportListenerTest extends TestCase
{
    public function testItSavesTheReport(): void
    {
        $report = new TechnicalSeoReport('https://example.com/');

        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->expects($this->once())
            ->method('save')
            ->with($report)
            ->willReturn('/tmp/report.json');

        (new StoreReportListener($storage))(new CrawlCompletedEvent($report));
    }

    public function testItDoesNothingWithoutStorage(): void
    {
        $this->expectNotToPerformAssertions();

        (new StoreReportListener())(new CrawlCompletedEvent(new TechnicalSeoReport('https://example.com/')));
    }

    public function testAStorageFailureDoesNotBubbleUp(): void
    {
        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->method('save')->willThrowException(new RuntimeException('Disk full'));

        (new StoreReportListener($storage))(new CrawlCompletedEvent(new TechnicalSeoReport('https://example.com/')));

        $this->addToAssertionCount(1);
    }
}
