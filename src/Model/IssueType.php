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

    // --- robots.txt ---
    case RobotsTxtServerError = 'robots_txt_server_error';
    case RobotsTxtDisallowAll = 'robots_txt_disallow_all';
    case RobotsTxtBlocksCanonicalTarget = 'robots_txt_blocks_canonical_target';
    case RobotsTxtBlocksHreflangAlternate = 'robots_txt_blocks_hreflang_alternate';

    // --- Sitemaps ---
    case SitemapMissing = 'sitemap_missing';
    case SitemapNotOk = 'sitemap_not_ok';
    case SitemapInvalid = 'sitemap_invalid';
    case SitemapUrlInvalid = 'sitemap_url_invalid';
    case SitemapUrlNotOk = 'sitemap_url_not_ok';
    case SitemapUrlRedirects = 'sitemap_url_redirects';
    case SitemapUrlNoindex = 'sitemap_url_noindex';
    case SitemapUrlNotCanonical = 'sitemap_url_not_canonical';
    case SitemapUrlBlockedByRobotsTxt = 'sitemap_url_blocked_by_robots_txt';
    case PageMissingFromSitemap = 'page_missing_from_sitemap';

    // --- Redirects ---
    case RedirectLoop = 'redirect_loop';
    case RedirectToError = 'redirect_to_error';
    case RedirectChainTooLong = 'redirect_chain_too_long';
    case TemporaryRedirect = 'temporary_redirect';
    case InternalLinkToRedirect = 'internal_link_to_redirect';
    case MetaRefreshRedirect = 'meta_refresh_redirect';

    // --- URL variants ---
    case HttpNotRedirectedToHttps = 'http_not_redirected_to_https';
    case HostVariantNotRedirected = 'host_variant_not_redirected';
    case IndexFileDuplicate = 'index_file_duplicate';
    case TrailingSlashDuplicate = 'trailing_slash_duplicate';
    case CaseDuplicate = 'case_duplicate';

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
            self::RobotsTxtServerError,
            self::RobotsTxtDisallowAll,
            self::RobotsTxtBlocksCanonicalTarget,
            self::RobotsTxtBlocksHreflangAlternate,
            self::SitemapNotOk,
            self::SitemapInvalid,
            self::SitemapUrlInvalid,
            self::SitemapUrlNotOk,
            self::SitemapUrlNoindex,
            self::SitemapUrlNotCanonical,
            self::SitemapUrlBlockedByRobotsTxt,
            self::RedirectLoop,
            self::RedirectToError,
            self::HttpNotRedirectedToHttps,
            self::HostVariantNotRedirected,
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
            self::SitemapUrlRedirects,
            self::InternalLinkToRedirect,
            self::MetaRefreshRedirect,
            self::IndexFileDuplicate,
            self::TrailingSlashDuplicate,
            self::CaseDuplicate,
            self::MissingHtmlLang => Severity::Warning,

            self::SitemapMissing,
            self::PageMissingFromSitemap,
            self::HreflangMissingXDefault => Severity::Notice,
        };
    }
}
