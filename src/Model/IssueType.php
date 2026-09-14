<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

enum IssueType: string
{
    // --- Canonical tags ---
    case CanonicalMultiple = 'canonical_multiple';
    case CanonicalNotInHead = 'canonical_not_in_head';
    case CanonicalRelative = 'canonical_relative';
    case CanonicalTargetNotOk = 'canonical_target_not_ok';
    case CanonicalTargetRedirects = 'canonical_target_redirects';
    case CanonicalTargetNoindex = 'canonical_target_noindex';
    case CanonicalChain = 'canonical_chain';

    // --- Indexing directives ---
    case NoindexOnLinkedPage = 'noindex_on_linked_page';
    case NoindexConflictsWithCanonical = 'noindex_conflicts_with_canonical';
    case RobotsDirectiveConflict = 'robots_directive_conflict';

    // --- Redirects ---
    case RedirectLoop = 'redirect_loop';
    case RedirectToError = 'redirect_to_error';
    case RedirectChainTooLong = 'redirect_chain_too_long';
    case TemporaryRedirect = 'temporary_redirect';
    case InternalLinkToRedirect = 'internal_link_to_redirect';
    case MetaRefreshRedirect = 'meta_refresh_redirect';

    // --- hreflang ---
    case HreflangInvalidCode = 'hreflang_invalid_code';
    case HreflangNotInHead = 'hreflang_not_in_head';
    case HreflangRelativeUrl = 'hreflang_relative_url';
    case HreflangConflictingUrls = 'hreflang_conflicting_urls';
    case HreflangMissingSelf = 'hreflang_missing_self';
    case HreflangNotReciprocal = 'hreflang_not_reciprocal';
    case HreflangTargetNotOk = 'hreflang_target_not_ok';
    case HreflangTargetRedirects = 'hreflang_target_redirects';
    case HreflangTargetNotCanonical = 'hreflang_target_not_canonical';
    case HreflangTargetNoindex = 'hreflang_target_noindex';
    case HreflangCanonicalMismatch = 'hreflang_canonical_mismatch';
    case HreflangMissingXDefault = 'hreflang_missing_x_default';

    // --- Page-level markup ---
    case MissingHtmlLang = 'missing_html_lang';

    public function severity(): Severity
    {
        return match ($this) {
            self::CanonicalMultiple,
            self::CanonicalNotInHead,
            self::CanonicalTargetNotOk,
            self::CanonicalTargetRedirects,
            self::CanonicalTargetNoindex,
            self::NoindexOnLinkedPage,
            self::NoindexConflictsWithCanonical,
            self::RobotsDirectiveConflict,
            self::RedirectLoop,
            self::RedirectToError,
            self::HreflangInvalidCode,
            self::HreflangNotInHead,
            self::HreflangRelativeUrl,
            self::HreflangConflictingUrls,
            self::HreflangMissingSelf,
            self::HreflangNotReciprocal,
            self::HreflangTargetNotOk,
            self::HreflangTargetRedirects,
            self::HreflangTargetNotCanonical,
            self::HreflangTargetNoindex,
            self::HreflangCanonicalMismatch => Severity::Error,

            self::CanonicalRelative,
            self::CanonicalChain,
            self::RedirectChainTooLong,
            self::TemporaryRedirect,
            self::InternalLinkToRedirect,
            self::MetaRefreshRedirect,
            self::MissingHtmlLang => Severity::Warning,

            self::HreflangMissingXDefault => Severity::Notice,
        };
    }
}
