<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Auditor;

use Lbonnet\TechnicalSeoBundle\Http\TargetProbeInterface;
use Lbonnet\TechnicalSeoBundle\Model\CrawlContext;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Lbonnet\TechnicalSeoBundle\Model\RedirectChain;
use Lbonnet\TechnicalSeoBundle\Model\RedirectHop;
use Lbonnet\TechnicalSeoBundle\Url\UrlResolver;

final class SiteAuditor implements SiteAuditorInterface
{
    /** @var array<string, true> */
    private readonly array $disabledChecks;

    /**
     * @param list<string> $disabledChecks IssueType values to drop from the report
     */
    public function __construct(
        private readonly TargetProbeInterface $probe,
        private readonly int $maxRedirectHops = 1,
        array $disabledChecks = [],
    ) {
        $disabled = [];

        foreach ($disabledChecks as $check) {
            $disabled[$check] = true;
        }

        $this->disabledChecks = $disabled;
    }

    public function audit(array $pages, CrawlContext $context): array
    {
        $this->probe->reset();

        /** @var array<string, PageAudit> $pagesByKey */
        $pagesByKey = [];

        foreach ($pages as $page) {
            $pagesByKey[UrlResolver::dedupKey($page->url)] = $page;
        }

        /** @var array<string, list<Issue>> $extraIssues dedup key of a page URL => issues to add */
        $extraIssues = [];
        /** @var list<PageAudit> $extraPages */
        $extraPages = [];

        foreach ($pages as $page) {
            $issues = $this->auditCanonicalTarget($page, $context, $pagesByKey);

            if ($issues !== []) {
                $extraIssues[UrlResolver::dedupKey($page->url)] = $issues;
            }
        }

        foreach ($context->redirectChains() as $chain) {
            $chainIssues = $this->auditRedirectChain($chain);

            if ($chainIssues !== []) {
                $extraPages[] = new PageAudit(
                    url: $chain->startUrl,
                    statusCode: $chain->startStatusCode,
                    issues: $chainIssues,
                );
            }

            foreach ($context->referrersOf($chain->startUrl) as $referrerUrl) {
                $key = UrlResolver::dedupKey($referrerUrl);
                $extraIssues[$key] = [...($extraIssues[$key] ?? []), $this->linkToRedirectIssue($chain)];
            }
        }

        $audited = array_map(
            function (PageAudit $page) use ($extraIssues): PageAudit {
                $key = UrlResolver::dedupKey($page->url);

                return $this->withoutDisabledChecks($page->withAddedIssues($extraIssues[$key] ?? []));
            },
            $pages,
        );

        foreach ($extraPages as $extraPage) {
            $audited[] = $this->withoutDisabledChecks($extraPage);
        }

        return $audited;
    }

    /**
     * @param array<string, PageAudit> $pagesByKey
     *
     * @return list<Issue>
     */
    private function auditCanonicalTarget(PageAudit $page, CrawlContext $context, array $pagesByKey): array
    {
        $target = $page->signals?->canonicalElsewhere($page->url);

        if ($target === null) {
            return [];
        }

        $response = $context->responseFor($target) ?? $this->probe->probe($target);

        if ($response === null) {
            return [];
        }

        if ($response->isRedirect()) {
            return [
                new Issue(
                    IssueType::CanonicalTargetRedirects,
                    sprintf(
                        'The canonical URL "%s" answers %d instead of 200; point it at the final URL.',
                        $target,
                        $response->statusCode,
                    ),
                ),
            ];
        }

        if ($response->isError()) {
            return [
                new Issue(
                    IssueType::CanonicalTargetNotOk,
                    sprintf(
                        'The canonical URL "%s" answers %d, so this page has no valid canonical.',
                        $target,
                        $response->statusCode,
                    ),
                ),
            ];
        }

        $targetPage = $pagesByKey[UrlResolver::dedupKey($target)] ?? null;

        return [
            ...$this->auditCanonicalTargetNoindex($target, $response, $targetPage),
            ...$this->auditCanonicalChain($page, $target, $targetPage),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonicalTargetNoindex(string $target, PageResponse $response, ?PageAudit $targetPage): array
    {
        $noindex = $response->headerRobotsDirectives()->hasNoindex()
            || $targetPage?->signals?->metaRobotsDirectives()->hasNoindex() === true;

        if (!$noindex) {
            return [];
        }

        return [
            new Issue(
                IssueType::CanonicalTargetNoindex,
                sprintf(
                    'The canonical URL "%s" carries a noindex directive: '
                    .'this page defers to a URL that refuses to be indexed.',
                    $target,
                ),
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonicalChain(PageAudit $page, string $target, ?PageAudit $targetPage): array
    {
        if ($targetPage === null) {
            return [];
        }

        $nextTarget = $targetPage->signals?->canonicalElsewhere($targetPage->url);

        if ($nextTarget === null) {
            return [];
        }

        if (UrlResolver::dedupKey($nextTarget) === UrlResolver::dedupKey($page->url)) {
            return [
                new Issue(
                    IssueType::CanonicalChain,
                    sprintf(
                        'The canonical URL "%s" points back to this page; the two pages cancel each other out.',
                        $target,
                    ),
                ),
            ];
        }

        return [
            new Issue(
                IssueType::CanonicalChain,
                sprintf(
                    'The canonical URL "%s" itself declares "%s" as canonical; '
                    .'point this page straight at the final one.',
                    $target,
                    $nextTarget,
                ),
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditRedirectChain(RedirectChain $chain): array
    {
        if ($chain->isLoop) {
            return [
                new Issue(
                    IssueType::RedirectLoop,
                    sprintf(
                        'The redirect from "%s" loops back on itself after %d hop(s).',
                        $chain->startUrl,
                        $chain->hopCount(),
                    ),
                ),
            ];
        }

        $issues = [];

        if ($chain->hopCount() > $this->maxRedirectHops) {
            $issues[] = new Issue(
                IssueType::RedirectChainTooLong,
                sprintf(
                    '"%s" goes through %d redirects%s before reaching "%s".',
                    $chain->startUrl,
                    $chain->hopCount(),
                    $chain->truncated ? ' or more' : '',
                    $chain->finalUrl ?? 'an unknown URL',
                ),
            );
        }

        $temporaryHops = $chain->temporaryHops();

        if ($temporaryHops !== []) {
            $issues[] = new Issue(
                IssueType::TemporaryRedirect,
                sprintf(
                    'The redirect from "%s" is temporary (%s): search engines tend to keep the original URL indexed. '
                    .'Use 301 or 308 if the move is permanent.',
                    $chain->startUrl,
                    implode(
                        ', ',
                        array_map(
                            static fn(RedirectHop $hop): string => sprintf('%d at "%s"', $hop->statusCode, $hop->url),
                            $temporaryHops,
                        ),
                    ),
                ),
            );
        }

        if ($chain->endsInError()) {
            $issues[] = new Issue(
                IssueType::RedirectToError,
                sprintf(
                    'The redirect from "%s" ends on "%s", which answers %d; fix the redirect target.',
                    $chain->startUrl,
                    (string)$chain->finalUrl,
                    (int)$chain->finalStatusCode,
                ),
            );
        }

        return $issues;
    }

    private function linkToRedirectIssue(RedirectChain $chain): Issue
    {
        if (!$chain->endsSuccessfully()) {
            return new Issue(
                IssueType::InternalLinkToRedirect,
                sprintf(
                    'This page links to "%s", which answers %d and never leads to a working page.',
                    $chain->startUrl,
                    $chain->startStatusCode,
                ),
            );
        }

        return new Issue(
            IssueType::InternalLinkToRedirect,
            sprintf(
                'This page links to "%s", which answers %d and redirects to "%s"; link to the final URL instead.',
                $chain->startUrl,
                $chain->startStatusCode,
                (string)$chain->finalUrl,
            ),
        );
    }

    private function withoutDisabledChecks(PageAudit $page): PageAudit
    {
        if ($this->disabledChecks === []) {
            return $page;
        }

        $kept = array_values(
            array_filter(
                $page->issues,
                fn(Issue $issue): bool => !isset($this->disabledChecks[$issue->type->value]),
            )
        );

        return count($kept) === count($page->issues) ? $page : $page->withIssues($kept);
    }
}
