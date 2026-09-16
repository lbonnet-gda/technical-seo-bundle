<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle;

use Lbonnet\CrawlerToolkit\Http\ThrottledHttpClient;
use Lbonnet\TechnicalSeoBundle\Crawler\SiteCrawler;
use Lbonnet\TechnicalSeoBundle\Model\IssueType;
use Lbonnet\TechnicalSeoBundle\Model\Severity;
use Lbonnet\TechnicalSeoBundle\Storage\JsonFileReportStorage;
use Lbonnet\TechnicalSeoBundle\Storage\ReportStorageInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TechnicalSeoBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $children = $rootNode->children();

        $children->scalarNode('base_url')
            ->defaultNull()
            ->info('Default base URL to crawl when none is passed to the command.')
            ->end();

        $children->integerNode('max_depth')
            ->defaultValue(3)
            ->min(0)
            ->info('Maximum crawl depth from the starting URL.')
            ->end();

        $children->integerNode('timeout')
            ->defaultValue(10)
            ->min(1)
            ->info('Per-request timeout in seconds when fetching a page.')
            ->end();

        $children->scalarNode('user_agent')
            ->defaultValue(SiteCrawler::DEFAULT_USER_AGENT)
            ->info(
                'User-Agent header sent when fetching a page. Identify your crawler honestly; do not spoof a browser UA to bypass bot protection.'
            )
            ->end();

        $children->arrayNode('exclude_patterns')
            ->info('Regular expression patterns for URLs to skip.')
            ->scalarPrototype()->end();

        $children->integerNode('max_redirect_hops')
            ->defaultValue(1)
            ->min(0)
            ->info(
                'How many redirects a URL may go through before the chain is reported as too long. The default of 1 means "one redirect is fine, a chain is not".'
            )
            ->end();

        $children->booleanNode('resolve_external_targets')
            ->defaultTrue()
            ->info(
                'Request canonical and hreflang targets that the crawl did not already visit, to check whether they answer 200. Disable to keep the audit strictly within the pages that were crawled.'
            )
            ->end();

        $children->integerNode('max_external_target_checks')
            ->defaultValue(200)
            ->min(0)
            ->info(
                'Maximum number of such extra requests per crawl (0 = unlimited). Targets beyond that budget are simply not reported on.'
            )
            ->end();

        $children->integerNode('url_variants_sample_size')
            ->defaultValue(10)
            ->min(0)
            ->info(
                'How many crawled pages, shallowest first, to request again with their trailing slash toggled and their letter case changed, to catch duplicate URLs. The http://, www/apex and index file versions of the home page are always checked. Set to 0 to skip the per-page checks.'
            )
            ->end();

        $children->enumNode('fail_on')
            ->values([Severity::Error->value, Severity::Warning->value, Severity::Notice->value])
            ->defaultValue(Severity::Error->value)
            ->info(
                'Lowest severity that makes the console command exit with a failure code. Every issue is always reported; this only decides what breaks a build.'
            )
            ->end();

        $children->arrayNode('disabled_checks')
            ->info('Checks to leave out of the report entirely, by issue type (e.g. "internal_link_to_redirect").')
            ->scalarPrototype()
            ->validate()
            ->ifTrue(static fn(mixed $value): bool => !is_string($value) || IssueType::tryFrom($value) === null)
            ->thenInvalid('Unknown check %s. Expected one of the "Lbonnet\TechnicalSeoBundle\Model\IssueType" values.')
            ->end()
            ->end();

        $children->scalarNode('storage_dir')
            ->defaultValue('%kernel.project_dir%/var/technical_seo')
            ->info('Directory where audit reports in JSON will be stored. Set to empty or null to disable.')
            ->end();

        $children->integerNode('storage_max_reports')
            ->defaultValue(30)
            ->min(0)
            ->info(
                'Maximum number of stored reports to keep per crawled URL; the oldest are deleted past that. Set to 0 to keep every report forever.'
            )
            ->end();

        $children->booleanNode('allow_private_network')
            ->defaultFalse()
            ->info(
                'Allow requests to URLs resolving to private/loopback/link-local IP ranges (e.g. 127.0.0.1, 10.0.0.0/8, cloud metadata endpoints). The crawler follows links and redirects found on the pages it visits, so leaving this disabled (default) prevents SSRF if it ever crawls untrusted or third-party content. Enable only to intentionally audit an internal network.'
            )
            ->end();

        $children->integerNode('request_delay_ms')
            ->defaultValue(200)
            ->min(0)
            ->info(
                'Minimum delay, in milliseconds, enforced between consecutive requests to the same host. The host you\'re crawling (the "url" argument/"base_url") is always exempt, so this only slows down requests to other hosts. Set to 0 to disable throttling entirely.'
            )
            ->end();

        $children->booleanNode('respect_robots_txt')
            ->defaultTrue()
            ->info(
                'Fetch and honor the crawled site\'s robots.txt: matching Disallow rules stop the crawler from following/auditing further internal pages under that path. Does not apply to the URL you explicitly start the crawl from. The robots.txt checks audit the file for Googlebot either way.'
            )
            ->end();
    }

    /**
     * @param array{
     *     base_url: string|null,
     *     max_depth: int,
     *     timeout: int,
     *     user_agent: string,
     *     exclude_patterns: list<string>,
     *     max_redirect_hops: int,
     *     resolve_external_targets: bool,
     *     max_external_target_checks: int,
     *     url_variants_sample_size: int,
     *     fail_on: string,
     *     disabled_checks: list<string>,
     *     storage_dir: string|null,
     *     storage_max_reports: int,
     *     allow_private_network: bool,
     *     request_delay_ms: int,
     *     respect_robots_txt: bool,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()
            ->set('technical_seo.base_url', $config['base_url'])
            ->set('technical_seo.max_depth', $config['max_depth'])
            ->set('technical_seo.timeout', $config['timeout'])
            ->set('technical_seo.user_agent', $config['user_agent'])
            ->set('technical_seo.exclude_patterns', $config['exclude_patterns'])
            ->set('technical_seo.max_redirect_hops', $config['max_redirect_hops'])
            ->set('technical_seo.resolve_external_targets', $config['resolve_external_targets'])
            ->set('technical_seo.max_external_target_checks', $config['max_external_target_checks'])
            ->set('technical_seo.url_variants_sample_size', $config['url_variants_sample_size'])
            ->set('technical_seo.fail_on', $config['fail_on'])
            ->set('technical_seo.disabled_checks', $config['disabled_checks'])
            ->set('technical_seo.storage_dir', $config['storage_dir'])
            ->set('technical_seo.storage_max_reports', $config['storage_max_reports'])
            ->set('technical_seo.allow_private_network', $config['allow_private_network'])
            ->set('technical_seo.request_delay_ms', $config['request_delay_ms'])
            ->set('technical_seo.respect_robots_txt', $config['respect_robots_txt']);

        $privateNetworkGuardId = 'technical_seo.http_client.private_network_guard';

        if ($config['allow_private_network']) {
            $builder->setAlias($privateNetworkGuardId, HttpClientInterface::class);
        } else {
            $builder->register($privateNetworkGuardId, NoPrivateNetworkHttpClient::class)
                ->setArguments([new Reference(HttpClientInterface::class)]);
        }

        $builder->register('technical_seo.http_client', ThrottledHttpClient::class)
            ->setArguments([new Reference($privateNetworkGuardId), $config['request_delay_ms']]);

        if ($config['storage_dir'] === null || $config['storage_dir'] === '') {
            $builder->removeDefinition(JsonFileReportStorage::class);
            $builder->removeAlias(ReportStorageInterface::class);
        }
    }
}
