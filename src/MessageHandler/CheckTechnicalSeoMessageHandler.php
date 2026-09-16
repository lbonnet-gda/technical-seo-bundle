<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\MessageHandler;

use Lbonnet\TechnicalSeoBundle\Crawler\CrawlerInterface;
use Lbonnet\TechnicalSeoBundle\Message\CheckTechnicalSeoMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckTechnicalSeoMessageHandler
{
    public function __construct(
        private readonly CrawlerInterface $crawler,
        private readonly ?string $defaultBaseUrl = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CheckTechnicalSeoMessage $message): void
    {
        $startUrl = $message->startUrl ?? $this->defaultBaseUrl;

        if ($startUrl === null || trim($startUrl) === '') {
            $this->logger->error('[TechnicalSeo] No base URL configured or provided in CheckTechnicalSeoMessage.');

            return;
        }

        $this->logger->info(sprintf('[TechnicalSeo] Async crawl starting on: %s', $startUrl));

        $this->crawler->crawl(
            startUrl: $startUrl,
            maxDepth: $message->maxDepth,
            excludePatterns: $message->excludePatterns,
            maxPages: $message->maxPages,
        );
    }
}
