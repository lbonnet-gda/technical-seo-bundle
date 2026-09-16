# TechnicalSeoBundle

[![CI](https://github.com/lbonnet-gda/technical-seo-bundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/lbonnet-gda/technical-seo-bundle/actions/workflows/ci.yaml)
[![Latest Version](https://img.shields.io/packagist/v/lbonnet/technical-seo-bundle.svg)](https://packagist.org/packages/lbonnet/technical-seo-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/lbonnet/technical-seo-bundle.svg)](https://packagist.org/packages/lbonnet/technical-seo-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Symfony bundle to crawl a site and audit the signals that decide **whether a page gets indexed, and indexed once**:
canonical tags, robot directives, and redirects.

Designed to run **outside the request/response cycle** — as a console command, a scheduled cron, or an async Messenger
worker — so it fits both CI pipelines and continuous monitoring of a live site.

Unlike a content crawler, it never lets the HTTP client follow redirects: a 3xx is a finding, not a detour. Every page
is requested with `max_redirects = 0` and chains are walked explicitly, so each hop stays visible.

## Requirements

- PHP >= 8.1
- Symfony 6.4, 7.x, or 8.x

## Installation

```bash
composer require lbonnet/technical-seo-bundle
```

If you don't use Symfony Flex, enable the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    Lbonnet\TechnicalSeoBundle\TechnicalSeoBundle::class => ['all' => true],
];
```

## Checks

Every issue carries a **severity**, and only `error` breaks a build by default (see `fail_on`).

### Canonical tags

| Check                        | Severity | What it catches                                                                             |
|------------------------------|----------|---------------------------------------------------------------------------------------------|
| `canonical_multiple`         | error    | Several conflicting `<link rel="canonical">`: search engines ignore all of them             |
| `canonical_not_in_head`      | error    | A canonical outside `<head>` — usually an invalid element ending `<head>` early             |
| `canonical_target_not_ok`    | error    | The canonical URL answers 4xx/5xx                                                           |
| `canonical_target_redirects` | error    | The canonical URL answers 3xx instead of 200                                                |
| `canonical_target_noindex`   | error    | The canonical URL carries a `noindex` (meta tag, or `X-Robots-Tag` for an uncrawled target) |
| `canonical_relative`         | warning  | A canonical href that is not an absolute URL (an empty href included)                       |
| `canonical_chain`            | warning  | The canonical URL declares yet another canonical (A → B → C), or points back (A ↔ B)        |

### Indexing directives

| Check                              | Severity | What it catches                                                                      |
|------------------------------------|----------|--------------------------------------------------------------------------------------|
| `noindex_on_linked_page`           | error    | A page the site links to, but tells search engines not to index                      |
| `noindex_conflicts_with_canonical` | error    | A `noindex` combined with a canonical pointing at another URL: contradictory signals |
| `robots_directive_conflict`        | error    | The `robots` meta tag and the `X-Robots-Tag` header contradict each other            |

### robots.txt

| Check                                  | Severity | What it catches                                                                                                   |
|----------------------------------------|----------|-------------------------------------------------------------------------------------------------------------------|
| `robots_txt_server_error`              | error    | `robots.txt` answers 5xx or 429, or cannot be fetched at all (timeout, DNS): Google stops crawling the whole site |
| `robots_txt_disallow_all`              | error    | `robots.txt` blocks Googlebot from the site root                                                                  |
| `robots_txt_blocks_canonical_target`   | error    | A canonical URL is blocked for Googlebot                                                                          |
| `robots_txt_blocks_hreflang_alternate` | error    | An hreflang alternate is blocked for Googlebot                                                                    |

These checks read `robots.txt` the way Google does and evaluate its rules for Googlebot, whatever user agent the crawler
sends, and whatever `respect_robots_txt` says. A problem with the file itself is reported once, on its URL. A blocked
canonical target or hreflang alternate is not checked any further, since Google cannot read it anyway.

### Redirects

| Check                       | Severity | What it catches                                                                    |
|-----------------------------|----------|------------------------------------------------------------------------------------|
| `redirect_loop`             | error    | A chain that comes back to a URL it already visited                                |
| `redirect_to_error`         | error    | A chain that ends on a 4xx/5xx                                                     |
| `redirect_chain_too_long`   | warning  | More hops than `max_redirect_hops` allows                                          |
| `temporary_redirect`        | warning  | A 302, 303 or 307 on the way: search engines tend to keep the original URL indexed |
| `internal_link_to_redirect` | warning  | An internal link pointing at a redirect instead of its target                      |
| `meta_refresh_redirect`     | warning  | `<meta http-equiv="refresh">` used instead of a 301                                |

An issue about a redirect itself (`redirect_loop`, `redirect_to_error`, `redirect_chain_too_long`, `temporary_redirect`)
is reported **once**, on the redirecting URL, however many pages link to it: that is where it gets fixed. Each linking
page gets its own `internal_link_to_redirect` instead.

### hreflang

| Check                           | Severity | What it catches                                                                                                                            |
|---------------------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `hreflang_invalid_code`         | error    | Not an ISO 639-1 language, with an optional ISO 15924 script and ISO 3166-1 alpha-2 region (`en-UK`, `es-419`, `fr_FR`, a region alone...) |
| `hreflang_not_in_head`          | error    | hreflang links outside `<head>` — usually an invalid element ending `<head>` early                                                         |
| `hreflang_relative_url`         | error    | An alternate URL that is not fully qualified                                                                                               |
| `hreflang_conflicting_urls`     | error    | The same hreflang value declared for several URLs                                                                                          |
| `hreflang_missing_self`         | error    | The page lists its alternates but not itself                                                                                               |
| `hreflang_not_reciprocal`       | error    | A crawled alternate does not link back to the page, so both annotations are ignored                                                        |
| `hreflang_target_not_ok`        | error    | An alternate answers 4xx/5xx                                                                                                               |
| `hreflang_target_redirects`     | error    | An alternate answers 3xx instead of 200                                                                                                    |
| `hreflang_target_not_canonical` | error    | A crawled alternate declares another URL as its canonical                                                                                  |
| `hreflang_target_noindex`       | error    | An alternate carries a `noindex` (meta tag, or `X-Robots-Tag` for an uncrawled alternate)                                                  |
| `hreflang_canonical_mismatch`   | error    | A page listing itself as a language version declares another URL as its canonical                                                          |
| `hreflang_missing_x_default`    | notice   | No `x-default` fallback for unmatched languages                                                                                            |

Every hreflang check concerns pages that declare `<link rel="alternate" hreflang>` tags, so a monolingual site gets none
of them, with nothing to configure. Annotations sent through HTTP `Link` headers or XML sitemaps are not read.

A page whose canonical points to another URL and that does not list itself among its alternates, like a filtered or
paginated listing reusing the template's tags, is not audited for hreflang: search engines fold it into its canonical
and ignore its annotations.

### Markup

| Check               | Severity | What it catches                     |
|---------------------|----------|-------------------------------------|
| `missing_html_lang` | warning  | `<html>` without a `lang` attribute |

Broken links themselves are deliberately **not** reported here — that is what
[link-checker-bundle](https://github.com/lbonnet-gda/link-checker-bundle) is for. A URL that answers 4xx is still
recorded, so canonical targets, hreflang alternates, and redirect chains can be checked against it.

## Configuration

Create `config/packages/technical_seo.yaml`:

```yaml
technical_seo:
    base_url: 'https://example.com' # default site to crawl
    max_depth: 3 # crawl depth from the start URL
    timeout: 10 # per-request timeout (seconds)
    user_agent: 'Mozilla/5.0 (compatible; TechnicalSeoBundle/1.0; +https://github.com/lbonnet-gda/technical-seo-bundle)'
    exclude_patterns: # URLs matching these regexes are skipped
        - '#/admin#'
        - '#\.pdf$#'

    max_redirect_hops: 1 # how many redirects a URL may go through before the chain is reported
    resolve_external_targets: true # request canonical and hreflang targets the crawl did not visit, to check they answer 200
    max_external_target_checks: 200 # cap on those extra requests per crawl (0 = unlimited)

    fail_on: 'error' # lowest severity that makes the command exit non-zero: error, warning or notice
    disabled_checks: [ ] # issue types to leave out entirely, e.g. ['internal_link_to_redirect']

    storage_dir: '%kernel.project_dir%/var/technical_seo' # JSON reports directory; set to null/empty to disable
    storage_max_reports: 30 # oldest reports are deleted past this count per crawled URL (0 = keep forever)
    allow_private_network: false # set true only to intentionally audit an internal network (SSRF risk otherwise)
    request_delay_ms: 200 # minimum delay between requests to the same host; the audited host is exempt
    respect_robots_txt: true # skip pages disallowed by the crawled site's robots.txt and honor its Crawl-delay
```

`disabled_checks` values are validated against the known issue types at container build time, so a typo fails fast
instead of silently disabling nothing.

## Usage

### 1. Console Command (CLI & CI)

```bash
php bin/console technical-seo:check [url] [--max-depth=N] [--exclude=PATTERN ...] [--fail-on=error|warning|notice]
```

The `url` argument is optional if `technical_seo.base_url` is configured.

> [!TIP]
> **CI / Exit Codes:** every issue is always printed, but only issues at or above `--fail-on` (default: `error`) make
> the command exit with `1`. That way a warning-level finding shows up in the build log without breaking the pipeline,
> and you can tighten the threshold once the site is clean.

### 2. Asynchronous Execution (Messenger)

```php
use Lbonnet\TechnicalSeoBundle\Message\CheckTechnicalSeoMessage;
use Symfony\Component\Messenger\MessageBusInterface;

public function triggerAudit(MessageBusInterface $bus): void
{
    $bus->dispatch(new CheckTechnicalSeoMessage());

    // Or with custom parameters
    $bus->dispatch(new CheckTechnicalSeoMessage(
        startUrl: 'https://example.com/blog',
        maxDepth: 2,
        excludePatterns: ['#/preview#'],
    ));
}
```

### 3. Automated Monitoring (Symfony Scheduler)

```php
namespace App\Scheduler;

use Lbonnet\TechnicalSeoBundle\Message\CheckTechnicalSeoMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                // Run daily at 03:00 AM
                RecurringMessage::cron('0 3 * * *', new CheckTechnicalSeoMessage())
            );
    }
}
```

### 4. Custom Notifications & Event Handling

When a crawl completes, a `CrawlCompletedEvent` is dispatched:

```php
namespace App\EventListener;

use Lbonnet\TechnicalSeoBundle\Event\CrawlCompletedEvent;
use Lbonnet\TechnicalSeoBundle\Model\Severity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

#[AsEventListener]
final class TechnicalSeoNotificationListener
{
    public function __construct(
        private readonly NotifierInterface $notifier,
    ) {
    }

    public function __invoke(CrawlCompletedEvent $event): void
    {
        $report = $event->report;

        if (!$report->hasIssues(Severity::Error)) {
            return;
        }

        $message = sprintf(
            'Found %d indexing error(s) on %s (audited %d page(s) in %.2fs).',
            $report->getIssuesCount(Severity::Error),
            $report->startUrl,
            $report->totalChecked,
            $report->totalDuration
        );

        $this->notifier->send(new Notification($message, ['chat/slack', 'email']));
    }
}
```

## Reports

Unless `storage_dir` is disabled, each crawl is stored as JSON:

```json
{
    "startUrl": "https://example.com",
    "createdAt": "2026-09-09T03:00:12+00:00",
    "totalChecked": 128,
    "totalDuration": 41.7,
    "issuesCount": 6,
    "issuesBySeverity": {
        "error": 2,
        "warning": 4,
        "notice": 0
    },
    "pages": [
        {
            "url": "https://example.com/blog",
            "statusCode": 200,
            "depth": 1,
            "canonical": "https://example.com/blog/",
            "issues": [
                {
                    "type": "canonical_target_redirects",
                    "severity": "error",
                    "message": "The canonical URL \"https://example.com/blog/\" answers 301 instead of 200; point it at the final URL."
                }
            ]
        }
    ]
}
```

## Known trade-offs

- **A redirect target is requested twice**: once while resolving the chain (headers only, the body is canceled), then
  again to read its markup. This keeps chain resolution independent of crawling, at the cost of one extra HEAD-sized
  request per redirect.
- **`canonical_not_in_head` and `hreflang_not_in_head` read the page with libxml**, not with DomCrawler, whose parser
  changes across PHP and Symfony versions and, through `masterminds/html5`, never closes `<head>` early. Like browsers,
  libxml closes `<head>` on the usual culprits (a stray `<div>`, a tracking `<img>`, stray text, a misplaced
  `<iframe>`), but not on an `<svg>` or a custom element. It also keeps a `<noscript>` holding an `<img>` inside
  `<head>`, which is how a JavaScript-enabled crawler reads it.

## Security

To report a vulnerability, please don't open a public issue — see [SECURITY.md](SECURITY.md) for how to report it
privately.

## License

MIT — see [LICENSE](LICENSE).
