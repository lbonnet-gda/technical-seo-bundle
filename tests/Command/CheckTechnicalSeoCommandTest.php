<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Command;

use Lbonnet\TechnicalSeoBundle\Command\CheckTechnicalSeoCommand;
use Lbonnet\TechnicalSeoBundle\Crawler\CrawlerInterface;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class CheckTechnicalSeoCommandTest extends TestCase
{
    public function testItFailsWhenNoUrlIsProvided(): void
    {
        $tester = new CommandTester(new CheckTechnicalSeoCommand($this->createMock(CrawlerInterface::class)));

        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('No URL provided', $tester->getDisplay());
    }

    public function testItRejectsAnUnknownFailOnValue(): void
    {
        $command = new CheckTechnicalSeoCommand(
            $this->createMock(CrawlerInterface::class),
            defaultBaseUrl: 'https://example.com',
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::INVALID, $tester->execute(['--fail-on' => 'critical']));
        $this->assertStringContainsString('Invalid --fail-on value', $tester->getDisplay());
    }

    public function testItSucceedsWhenNothingIsFound(): void
    {
        $tester = $this->tester(new TechnicalSeoReport('https://example.com', [], 3, 0.12));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('All clear!', $tester->getDisplay());
    }

    public function testItFailsOnAnErrorLevelIssue(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::CanonicalMultiple));

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('canonical_multiple', $display);
        $this->assertStringContainsString('1 error(s)', $display);
    }

    public function testAWarningDoesNotBreakTheBuildByDefault(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::MissingHtmlLang));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        // Reported, but below the failure threshold.
        $this->assertStringContainsString('missing_html_lang', $display);
        $this->assertStringContainsString('No issue of severity "error" or above', $display);
    }

    public function testTheThresholdCanBeLoweredFromTheCommandLine(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::MissingHtmlLang));

        $this->assertSame(Command::FAILURE, $tester->execute(['--fail-on' => 'warning']));
    }

    private function reportWith(IssueType $type): TechnicalSeoReport
    {
        return new TechnicalSeoReport(
            startUrl: 'https://example.com',
            pages: [
                new PageAudit(
                    url: 'https://example.com',
                    statusCode: Response::HTTP_OK,
                    issues: [new Issue($type, 'Something to fix.')],
                ),
            ],
            totalChecked: 1,
            totalDuration: 0.15,
        );
    }

    private function tester(TechnicalSeoReport $report): CommandTester
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->method('crawl')->willReturn($report);

        return new CommandTester(
            new CheckTechnicalSeoCommand($crawler, defaultBaseUrl: 'https://example.com'),
        );
    }
}
