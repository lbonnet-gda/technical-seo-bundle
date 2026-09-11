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

| Check                        | Severity | What it catches                                                                 |
|------------------------------|----------|---------------------------------------------------------------------------------|
| `canonical_multiple`         | error    | Several conflicting `<link rel="canonical">`: search engines ignore all of them |
| `canonical_not_in_head`      | error    | A canonical outside `<head>` — usually an invalid element ending `<head>` early |
| `canonical_target_not_ok`    | error    | The canonical URL answers 4xx/5xx                                               |
| `canonical_target_redirects` | error    | The canonical URL answers 3xx instead of 200                                    |
| `canonical_relative`         | warning  | A canonical href that is not an absolute URL (an empty href included)           |

### Indexing directives

| Check                       | Severity | What it catches                                                           |
|-----------------------------|----------|---------------------------------------------------------------------------|
| `noindex_on_linked_page`    | error    | A page the site links to, but tells search engines not to index           |
| `robots_directive_conflict` | error    | The `robots` meta tag and the `X-Robots-Tag` header contradict each other |

### Redirects

| Check                       | Severity | What it catches                                               |
|-----------------------------|----------|---------------------------------------------------------------|
| `redirect_loop`             | error    | A chain that comes back to a URL it already visited           |
| `redirect_chain_too_long`   | warning  | More hops than `max_redirect_hops` allows                     |
| `internal_link_to_redirect` | warning  | An internal link pointing at a redirect instead of its target |
| `meta_refresh_redirect`     | warning  | `<meta http-equiv="refresh">` used instead of a 301           |

### Markup

| Check               | Severity | What it catches                     |
|---------------------|----------|-------------------------------------|
| `missing_html_lang` | warning  | `<html>` without a `lang` attribute |

Broken links themselves are deliberately **not** reported here — that is what
[link-checker-bundle](https://github.com/lbonnet-gda/link-checker-bundle) is for. A URL that answers 4xx is still
recorded, so canonical targets can be checked against it.

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
    resolve_external_targets: true # request canonical targets the crawl did not visit, to check they answer 200
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
- **`canonical_not_in_head` trusts the parsed tree**, not the source order. That is deliberate — it is the same
  head/body split a search engine's parser produces — but a handwritten test fixture and a browser may disagree on
  where an oddly placed `<link>` ends up.

## Security

To report a vulnerability, please don't open a public issue — see [SECURITY.md](SECURITY.md) for how to report it
privately.

## License

MIT — see [LICENSE](LICENSE).
