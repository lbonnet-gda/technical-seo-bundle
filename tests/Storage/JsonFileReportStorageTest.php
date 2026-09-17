<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Storage;

use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use Lbonnet\TechnicalSeoBundle\Storage\JsonFileReportStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class JsonFileReportStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/technical-seo-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testSaveWritesTheReportAsJson(): void
    {
        $storage = new JsonFileReportStorage($this->directory);

        $path = $storage->save($this->report());

        $this->assertFileExists($path);

        $decoded = json_decode((string)file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertSame('https://example.com/', $decoded['startUrl']);
        $this->assertSame(1, $decoded['totalChecked']);
        $this->assertFalse($decoded['truncated']);
        $this->assertFalse($decoded['blockedByRobotsTxt']);
        $this->assertSame(1, $decoded['issuesCount']);
        $this->assertSame(['error' => 1, 'warning' => 0, 'notice' => 0], $decoded['issuesBySeverity']);
        $this->assertSame('https://example.com/canonical', $decoded['pages'][0]['canonical']);
        $this->assertSame(Response::HTTP_OK, $decoded['pages'][0]['statusCode']);
        $this->assertSame('canonical_multiple', $decoded['pages'][0]['issues'][0]['type']);
        $this->assertSame('error', $decoded['pages'][0]['issues'][0]['severity']);
    }

    public function testRotationKeepsOnlyTheMostRecentReports(): void
    {
        $storage = new JsonFileReportStorage($this->directory, maxReports: 2);

        $storage->save($this->report());
        $storage->save($this->report());
        $storage->save($this->report());

        $this->assertCount(2, glob($this->directory.'/*.json') ?: []);
    }

    public function testRotationIsDisabledWithZero(): void
    {
        $storage = new JsonFileReportStorage($this->directory, maxReports: 0);

        $storage->save($this->report());
        $storage->save($this->report());

        $this->assertCount(2, glob($this->directory.'/*.json') ?: []);
    }

    private function report(): TechnicalSeoReport
    {
        return new TechnicalSeoReport(
            startUrl: 'https://example.com/',
            pages: [
                new PageAudit(
                    url: 'https://example.com/',
                    statusCode: Response::HTTP_OK,
                    issues: [new Issue(IssueType::CanonicalMultiple, 'Two canonicals.')],
                    signals: new HeadSignals(canonicalHrefs: ['https://example.com/canonical']),
                ),
            ],
            totalChecked: 1,
            totalDuration: 0.42,
        );
    }
}
