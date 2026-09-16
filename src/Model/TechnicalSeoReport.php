<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

final class TechnicalSeoReport
{
    /**
     * @param list<PageAudit> $pages
     * @param int $totalChecked number of HTML pages actually parsed and audited
     * @param bool $truncated the crawl stopped at the max_pages limit while URLs were still waiting to be fetched
     */
    public function __construct(
        public readonly string $startUrl,
        public readonly array $pages = [],
        public readonly int $totalChecked = 0,
        public readonly float $totalDuration = 0.0,
        public readonly bool $truncated = false,
    ) {
    }

    public function getIssuesCount(?Severity $atLeast = null): int
    {
        return array_sum(
            array_map(
                static fn(PageAudit $page): int => $page->countIssues($atLeast),
                $this->pages,
            )
        );
    }

    public function hasIssues(?Severity $atLeast = null): bool
    {
        return $this->getIssuesCount($atLeast) > 0;
    }

    /**
     * @return array<string, int> severity value => number of issues, highest severity first
     */
    public function getIssuesCountBySeverity(): array
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Notice->value => 0,
        ];

        foreach ($this->pages as $page) {
            foreach ($page->issues as $issue) {
                $counts[$issue->severity()->value]++;
            }
        }

        return $counts;
    }
}
