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

    // --- Indexing directives ---
    case NoindexOnLinkedPage = 'noindex_on_linked_page';
    case RobotsDirectiveConflict = 'robots_directive_conflict';

    // --- Redirects ---
    case RedirectLoop = 'redirect_loop';
    case RedirectChainTooLong = 'redirect_chain_too_long';
    case InternalLinkToRedirect = 'internal_link_to_redirect';
    case MetaRefreshRedirect = 'meta_refresh_redirect';

    // --- Page-level markup ---
    case MissingHtmlLang = 'missing_html_lang';

    public function severity(): Severity
    {
        return match ($this) {
            self::CanonicalMultiple,
            self::CanonicalNotInHead,
            self::CanonicalTargetNotOk,
            self::CanonicalTargetRedirects,
            self::NoindexOnLinkedPage,
            self::RobotsDirectiveConflict,
            self::RedirectLoop => Severity::Error,

            self::CanonicalRelative,
            self::RedirectChainTooLong,
            self::InternalLinkToRedirect,
            self::MetaRefreshRedirect,
            self::MissingHtmlLang => Severity::Warning,
        };
    }
}
