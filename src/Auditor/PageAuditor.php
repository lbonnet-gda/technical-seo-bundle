<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Auditor;

use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class PageAuditor implements PageAuditorInterface
{
    public function audit(PageResponse $response, HeadSignals $signals): array
    {
        return [
            ...$this->auditCanonical($signals),
            ...$this->auditIndexingDirectives($response, $signals),
            ...$this->auditMetaRefresh($signals),
            ...$this->auditHtmlLang($signals),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonical(HeadSignals $signals): array
    {
        $issues = [];
        $distinct = array_values(array_unique($signals->canonicalHrefs));

        if (count($distinct) > 1) {
            $issues[] = new Issue(
                IssueType::CanonicalMultiple,
                sprintf(
                    'The page declares %d conflicting canonical URLs (%s); search engines ignore all of them.',
                    count($distinct),
                    implode(', ', array_map(static fn(string $href): string => '"'.$href.'"', $distinct)),
                ),
            );
        }

        if ($signals->bodyCanonicalHrefs !== []) {
            $issues[] = new Issue(
                IssueType::CanonicalNotInHead,
                sprintf(
                    'A canonical link ("%s") sits outside <head> and is ignored. Check for an invalid element '
                    .'earlier in <head>, which ends it sooner than the source suggests.',
                    $signals->bodyCanonicalHrefs[0],
                ),
            );
        }

        foreach ($distinct as $href) {
            if (UrlResolver::isAbsoluteHttpUrl($href)) {
                continue;
            }

            $issues[] = new Issue(
                IssueType::CanonicalRelative,
                $href === ''
                    ? 'The canonical link has an empty href, which silently makes the page canonical to itself.'
                    : sprintf('The canonical href "%s" is not an absolute URL.', $href),
            );
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function auditIndexingDirectives(PageResponse $response, HeadSignals $signals): array
    {
        $issues = [];
        $meta = $signals->metaRobotsDirectives();
        $header = $response->headerRobotsDirectives();

        if (($meta->hasIndex() && $header->hasNoindex()) || ($meta->hasNoindex() && $header->hasIndex())) {
            $issues[] = new Issue(
                IssueType::RobotsDirectiveConflict,
                sprintf(
                    'The robots meta tag (%s) and the X-Robots-Tag header (%s) contradict each other; '
                    .'the most restrictive one wins, which is rarely what was intended.',
                    implode(', ', $meta->directives),
                    implode(', ', $header->directives),
                ),
            );
        }

        if ($response->depth >= 1 && ($meta->hasNoindex() || $header->hasNoindex())) {
            $issues[] = new Issue(
                IssueType::NoindexOnLinkedPage,
                sprintf(
                    'The page is linked from the site but carries a noindex directive (%s).',
                    $meta->hasNoindex()
                        ? 'robots meta tag: '.implode(', ', $meta->directives)
                        : 'X-Robots-Tag: '.implode(', ', $header->directives),
                ),
            );
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function auditMetaRefresh(HeadSignals $signals): array
    {
        if ($signals->metaRefreshUrl === null) {
            return [];
        }

        return [
            new Issue(
                IssueType::MetaRefreshRedirect,
                sprintf(
                    'The page redirects to "%s" with a meta refresh; use a 301 response instead.',
                    $signals->metaRefreshUrl,
                ),
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditHtmlLang(HeadSignals $signals): array
    {
        if ($signals->htmlLang !== null) {
            return [];
        }

        return [new Issue(IssueType::MissingHtmlLang, 'The <html> element has no lang attribute.')];
    }
}
