<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Auditor;

use Lbonnet\TechnicalSeoBundle\Auditor\PageAuditor;
use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PageAuditorTest extends TestCase
{
    public function testCleanPageHasNoIssue(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
        );

        $this->assertSame([], $issues);
    }

    public function testFlagsConflictingCanonicals(): void
    {
        $issues = $this->audit(
            new HeadSignals(
                canonicalHrefs: ['https://example.com/a', 'https://example.com/b'],
                htmlLang: 'fr',
            ),
        );

        $this->assertSame([IssueType::CanonicalMultiple], self::types($issues));
    }

    public function testFlagsCanonicalOutsideHead(): void
    {
        $issues = $this->audit(
            new HeadSignals(bodyCanonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
        );

        $this->assertSame([IssueType::CanonicalNotInHead], self::types($issues));
    }

    public function testFlagsRelativeCanonical(): void
    {
        $issues = $this->audit(new HeadSignals(canonicalHrefs: ['/a'], htmlLang: 'fr'));

        $this->assertSame([IssueType::CanonicalRelative], self::types($issues));
        $this->assertStringContainsString('"/a"', $issues[0]->message);
    }

    public function testFlagsEmptyCanonicalHref(): void
    {
        $issues = $this->audit(new HeadSignals(canonicalHrefs: [''], htmlLang: 'fr'));

        $this->assertSame([IssueType::CanonicalRelative], self::types($issues));
        $this->assertStringContainsString('empty href', $issues[0]->message);
    }

    public function testFlagsNoindexOnALinkedPage(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr'),
            depth: 2,
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testDoesNotFlagNoindexOnTheStartUrl(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr')
        );

        $this->assertSame([], $issues);
    }

    public function testFlagsNoindexComingFromTheHeaderOnly(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testFlagsDirectiveConflictBetweenMetaAndHeader(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['index, follow'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex']],
        );

        $this->assertSame(
            [IssueType::RobotsDirectiveConflict, IssueType::NoindexOnLinkedPage],
            self::types($issues),
        );
    }

    public function testAgreeingDirectivesAreNotAConflict(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex, nofollow']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testUserAgentPrefixedHeaderDirectiveIsUnderstood(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['googlebot: noindex']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testFlagsMetaRefresh(): void
    {
        $issues = $this->audit(
            new HeadSignals(
                canonicalHrefs: ['https://example.com/a'],
                metaRefreshUrl: 'https://example.com/new',
                htmlLang: 'fr',
            ),
        );

        $this->assertSame([IssueType::MetaRefreshRedirect], self::types($issues));
    }

    public function testFlagsNoindexCombinedWithACanonicalElsewhere(): void
    {
        $issues = $this->audit(
            new HeadSignals(canonicalHrefs: ['https://example.com/other'], metaRobots: ['noindex'], htmlLang: 'fr'),
        );

        $this->assertSame([IssueType::NoindexConflictsWithCanonical], self::types($issues));
    }

    public function testFlagsMissingHtmlLang(): void
    {
        $issues = $this->audit(new HeadSignals(canonicalHrefs: ['https://example.com/a']));

        $this->assertSame([IssueType::MissingHtmlLang], self::types($issues));
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<Issue>
     */
    private function audit(HeadSignals $signals, int $depth = 0, array $headers = []): array
    {
        $response = new PageResponse(
            url: 'https://example.com/a',
            statusCode: Response::HTTP_OK,
            headers: $headers,
            html: '<html></html>',
            depth: $depth,
        );

        return (new PageAuditor())->audit($response, $signals);
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
