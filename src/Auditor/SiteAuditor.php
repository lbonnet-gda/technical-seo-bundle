<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Auditor;

use Lbonnet\TechnicalSeoBundle\Http\TargetProbeInterface;
use Lbonnet\TechnicalSeoBundle\Model\CrawlContext;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\PageAudit;
use Lbonnet\TechnicalSeoBundle\Model\RedirectChain;
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

        /** @var array<string, list<Issue>> $extraIssues dedup key of a page URL => issues to add */
        $extraIssues = [];
        /** @var list<PageAudit> $extraPages */
        $extraPages = [];

        foreach ($pages as $page) {
            $issues = $this->auditCanonicalTarget($page, $context);

            if ($issues !== []) {
                $key = UrlResolver::dedupKey($page->url);
                $extraIssues[$key] = [...($extraIssues[$key] ?? []), ...$issues];
            }
        }

        foreach ($context->redirectChains() as $chain) {
            $chainIssues = $this->auditRedirectChain($chain);
            $referrers = $context->referrersOf($chain->startUrl);

            if ($referrers === []) {
                if ($chainIssues !== []) {
                    $extraPages[] = new PageAudit(
                        url: $chain->startUrl,
                        statusCode: $chain->startStatusCode,
                        issues: $chainIssues,
                    );
                }

                continue;
            }

            foreach ($referrers as $referrerUrl) {
                $key = UrlResolver::dedupKey($referrerUrl);
                $extraIssues[$key] = [
                    ...($extraIssues[$key] ?? []),
                    $this->linkToRedirectIssue($chain),
                    ...$chainIssues,
                ];
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
     * @return list<Issue>
     */
    private function auditCanonicalTarget(PageAudit $page, CrawlContext $context): array
    {
        $signals = $page->signals;

        if ($signals === null) {
            return [];
        }

        $href = $signals->effectiveCanonicalHref();

        if ($href === null || $href === '') {
            return [];
        }

        $target = UrlResolver::resolve($page->url, $href);

        if ($target === null || UrlResolver::dedupKey($target) === UrlResolver::dedupKey($page->url)) {
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

        return [];
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

        if ($chain->hopCount() > $this->maxRedirectHops) {
            return [
                new Issue(
                    IssueType::RedirectChainTooLong,
                    sprintf(
                        '"%s" goes through %d redirects%s before reaching "%s".',
                        $chain->startUrl,
                        $chain->hopCount(),
                        $chain->truncated ? ' or more' : '',
                        $chain->finalUrl ?? 'an unknown URL',
                    ),
                ),
            ];
        }

        return [];
    }

    private function linkToRedirectIssue(RedirectChain $chain): Issue
    {
        return new Issue(
            IssueType::InternalLinkToRedirect,
            sprintf(
                'This page links to "%s", which answers %d and redirects to "%s"; link to the final URL instead.',
                $chain->startUrl,
                $chain->startStatusCode,
                $chain->finalUrl ?? 'an unknown URL',
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
