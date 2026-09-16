<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests;

use Lbonnet\CrawlerToolkit\Http\ThrottledHttpClient;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtChecker;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtCheckerInterface;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtProviderInterface;
use Lbonnet\TechnicalSeoBundle\Auditor\PageAuditor;
use Lbonnet\TechnicalSeoBundle\Auditor\SiteAuditor;
use Lbonnet\TechnicalSeoBundle\Command\CheckTechnicalSeoCommand;
use Lbonnet\TechnicalSeoBundle\Crawler\CrawlerInterface;
use Lbonnet\TechnicalSeoBundle\Crawler\SiteCrawler;
use Lbonnet\TechnicalSeoBundle\Http\HeaderFetcher;
use Lbonnet\TechnicalSeoBundle\Http\HttpTargetProbe;
use Lbonnet\TechnicalSeoBundle\Http\RedirectChainResolver;
use Lbonnet\TechnicalSeoBundle\Http\TargetProbeInterface;
use Lbonnet\TechnicalSeoBundle\Storage\JsonFileReportStorage;
use Lbonnet\TechnicalSeoBundle\Storage\ReportStorageInterface;
use Lbonnet\TechnicalSeoBundle\TechnicalSeoBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TechnicalSeoBundleTest extends TestCase
{
    public function testDefaultConfigurationAndParameters(): void
    {
        $container = $this->load([]);

        $this->assertNull($container->getParameter('technical_seo.base_url'));
        $this->assertSame(3, $container->getParameter('technical_seo.max_depth'));
        $this->assertSame(10, $container->getParameter('technical_seo.timeout'));
        $this->assertSame(SiteCrawler::DEFAULT_USER_AGENT, $container->getParameter('technical_seo.user_agent'));
        $this->assertSame([], $container->getParameter('technical_seo.exclude_patterns'));
        $this->assertSame(1, $container->getParameter('technical_seo.max_redirect_hops'));
        $this->assertTrue($container->getParameter('technical_seo.resolve_external_targets'));
        $this->assertSame(200, $container->getParameter('technical_seo.max_external_target_checks'));
        $this->assertSame('error', $container->getParameter('technical_seo.fail_on'));
        $this->assertSame([], $container->getParameter('technical_seo.disabled_checks'));
        $this->assertSame(
            '%kernel.project_dir%/var/technical_seo',
            $container->getParameter('technical_seo.storage_dir')
        );
        $this->assertSame(30, $container->getParameter('technical_seo.storage_max_reports'));
        $this->assertFalse($container->getParameter('technical_seo.allow_private_network'));
        $this->assertSame(200, $container->getParameter('technical_seo.request_delay_ms'));
        $this->assertTrue($container->getParameter('technical_seo.respect_robots_txt'));

        $this->assertSame(
            NoPrivateNetworkHttpClient::class,
            $container->getDefinition('technical_seo.http_client.private_network_guard')->getClass()
        );

        $httpClientDefinition = $container->getDefinition('technical_seo.http_client');
        $this->assertSame(ThrottledHttpClient::class, $httpClientDefinition->getClass());
        $this->assertSame(
            'technical_seo.http_client.private_network_guard',
            (string)$httpClientDefinition->getArgument(0)
        );
        $this->assertSame(200, $httpClientDefinition->getArgument(1));
    }

    public function testAllowPrivateNetworkDisablesSsrfProtection(): void
    {
        $container = $this->load([
            'technical_seo' => ['allow_private_network' => true, 'request_delay_ms' => 250],
        ]);

        $this->assertTrue($container->hasAlias('technical_seo.http_client.private_network_guard'));
        $this->assertSame(
            HttpClientInterface::class,
            (string)$container->getAlias('technical_seo.http_client.private_network_guard')
        );
        $this->assertSame(250, $container->getDefinition('technical_seo.http_client')->getArgument(1));
    }

    public function testCustomConfigurationAndServiceRegistration(): void
    {
        $container = $this->load([
            'technical_seo' => [
                'base_url' => 'https://example.com',
                'max_depth' => 5,
                'max_redirect_hops' => 0,
                'resolve_external_targets' => false,
                'fail_on' => 'warning',
                'disabled_checks' => ['internal_link_to_redirect'],
                'exclude_patterns' => ['#/admin#'],
                'respect_robots_txt' => false,
            ],
        ]);

        $this->assertSame('https://example.com', $container->getParameter('technical_seo.base_url'));
        $this->assertSame(5, $container->getParameter('technical_seo.max_depth'));
        $this->assertSame(0, $container->getParameter('technical_seo.max_redirect_hops'));
        $this->assertFalse($container->getParameter('technical_seo.resolve_external_targets'));
        $this->assertSame('warning', $container->getParameter('technical_seo.fail_on'));
        $this->assertSame(['internal_link_to_redirect'], $container->getParameter('technical_seo.disabled_checks'));
        $this->assertSame(['#/admin#'], $container->getParameter('technical_seo.exclude_patterns'));
        $this->assertFalse($container->getParameter('technical_seo.respect_robots_txt'));

        foreach (
            [
                PageAuditor::class,
                SiteAuditor::class,
                SiteCrawler::class,
                RedirectChainResolver::class,
                HeaderFetcher::class,
                HttpTargetProbe::class,
                CheckTechnicalSeoCommand::class,
                JsonFileReportStorage::class,
                RobotsTxtChecker::class,
            ] as $serviceId
        ) {
            $this->assertTrue($container->hasDefinition($serviceId), $serviceId.' should be registered');
        }

        foreach (
            [
                CrawlerInterface::class,
                TargetProbeInterface::class,
                ReportStorageInterface::class,
                RobotsTxtCheckerInterface::class,
                RobotsTxtProviderInterface::class,
            ] as $alias
        ) {
            $this->assertTrue(
                $container->hasAlias($alias) || $container->hasDefinition($alias),
                $alias.' should be resolvable'
            );
        }
    }

    public function testAnUnknownDisabledCheckIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['technical_seo' => ['disabled_checks' => ['not_a_real_check']]]);
    }

    public function testAnUnknownFailOnValueIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['technical_seo' => ['fail_on' => 'critical']]);
    }

    public function testEmptyStorageDirDisablesReportStorage(): void
    {
        $container = $this->load(['technical_seo' => ['storage_dir' => '']]);

        $this->assertFalse($container->hasDefinition(JsonFileReportStorage::class));
        $this->assertFalse(
            $container->hasAlias(ReportStorageInterface::class)
            || $container->hasDefinition(ReportStorageInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $tempDir = sys_get_temp_dir();

        $container = new ContainerBuilder(new ParameterBag([
            'kernel.debug' => false,
            'kernel.project_dir' => $tempDir,
            'kernel.build_dir' => $tempDir,
            'kernel.cache_dir' => $tempDir,
            'kernel.charset' => 'UTF-8',
            'kernel.environment' => 'test',
        ]));

        $extension = (new TechnicalSeoBundle())->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load($config, $container);

        return $container;
    }
}
