<?php

declare(strict_types=1);

use Lbonnet\CrawlerToolkit\Robots\RobotsTxtChecker;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtCheckerInterface;
use Lbonnet\CrawlerToolkit\Robots\RobotsTxtProviderInterface;
use Lbonnet\TechnicalSeoBundle\Auditor\SiteAuditor;
use Lbonnet\TechnicalSeoBundle\Auditor\UrlVariantAuditor;
use Lbonnet\TechnicalSeoBundle\Command\CheckTechnicalSeoCommand;
use Lbonnet\TechnicalSeoBundle\Crawler\SiteCrawler;
use Lbonnet\TechnicalSeoBundle\Http\HeaderFetcher;
use Lbonnet\TechnicalSeoBundle\Http\HttpTargetProbe;
use Lbonnet\TechnicalSeoBundle\Http\PageFetcher;
use Lbonnet\TechnicalSeoBundle\MessageHandler\CheckTechnicalSeoMessageHandler;
use Lbonnet\TechnicalSeoBundle\Storage\JsonFileReportStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Lbonnet\\TechnicalSeoBundle\\', '../src/')
        ->exclude([
            '../src/TechnicalSeoBundle.php',
            '../src/DependencyInjection/',
            '../src/Model/',
            '../src/Event/',
            '../src/Message/',
            '../src/Url/',
            '../src/Hreflang/',
        ]);

    $services->set(SiteCrawler::class)
        ->arg('$httpClient', service('technical_seo.http_client'))
        ->arg('$defaultMaxDepth', param('technical_seo.max_depth'))
        ->arg('$defaultMaxPages', param('technical_seo.max_pages'))
        ->arg('$defaultTimeout', param('technical_seo.timeout'))
        ->arg('$userAgent', param('technical_seo.user_agent'))
        ->arg('$defaultExcludePatterns', param('technical_seo.exclude_patterns'));

    $services->set(HeaderFetcher::class)
        ->arg('$httpClient', service('technical_seo.http_client'))
        ->arg('$timeout', param('technical_seo.timeout'))
        ->arg('$userAgent', param('technical_seo.user_agent'));

    $services->set(PageFetcher::class)
        ->arg('$httpClient', service('technical_seo.http_client'))
        ->arg('$timeout', param('technical_seo.timeout'))
        ->arg('$userAgent', param('technical_seo.user_agent'));

    $services->set(HttpTargetProbe::class)
        ->arg('$enabled', param('technical_seo.resolve_external_targets'))
        ->arg('$maxProbes', param('technical_seo.max_external_target_checks'));

    $services->set(SiteAuditor::class)
        ->arg('$maxRedirectHops', param('technical_seo.max_redirect_hops'))
        ->arg('$disabledChecks', param('technical_seo.disabled_checks'));

    $services->set(UrlVariantAuditor::class)
        ->arg('$sampleSize', param('technical_seo.url_variants_sample_size'))
        ->arg('$disabledChecks', param('technical_seo.disabled_checks'));

    $services->set(RobotsTxtChecker::class)
        ->arg('$httpClient', service('technical_seo.http_client'))
        ->arg('$userAgent', param('technical_seo.user_agent'))
        ->arg('$enabled', param('technical_seo.respect_robots_txt'));

    $services->alias(RobotsTxtCheckerInterface::class, RobotsTxtChecker::class);
    $services->alias(RobotsTxtProviderInterface::class, RobotsTxtChecker::class);

    $services->set(CheckTechnicalSeoCommand::class)
        ->arg('$defaultBaseUrl', param('technical_seo.base_url'))
        ->arg('$defaultFailOn', param('technical_seo.fail_on'));

    $services->set(CheckTechnicalSeoMessageHandler::class)
        ->arg('$defaultBaseUrl', param('technical_seo.base_url'));

    $services->set(JsonFileReportStorage::class)
        ->arg('$storageDirectory', param('technical_seo.storage_dir'))
        ->arg('$maxReports', param('technical_seo.storage_max_reports'));
};
