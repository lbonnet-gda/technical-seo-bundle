<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Command;

use Lbonnet\TechnicalSeoBundle\Crawler\CrawlerInterface;
use Lbonnet\TechnicalSeoBundle\Model\Severity;
use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

#[AsCommand(
    name: 'technical-seo:check',
    description: 'Crawls a website and audits technical SEO signals (canonical tags, indexing directives, redirects).',
)]
final class CheckTechnicalSeoCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly CrawlerInterface $crawler,
        private readonly ?string $defaultBaseUrl = null,
        private readonly string $defaultFailOn = 'error',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'The starting URL to crawl (defaults to technical_seo.base_url)'
            )
            ->addOption(
                'max-depth',
                'd',
                InputOption::VALUE_REQUIRED,
                'Override the maximum crawl depth'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional regex patterns for URLs to exclude'
            )
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'Lowest severity that makes the command fail: error, warning or notice'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('The "technical-seo:check" command is already running in another process. Skipping.');

            return Command::SUCCESS;
        }

        try {
            /** @var string|null $startUrl */
            $startUrl = $input->getArgument('url') ?? $this->defaultBaseUrl;

            if ($startUrl === null || trim($startUrl) === '') {
                $io->error('No URL provided. Pass an URL as argument or configure "technical_seo.base_url".');

                return Command::INVALID;
            }

            /** @var string|null $failOnOption */
            $failOnOption = $input->getOption('fail-on');
            $failOn = Severity::tryFrom($failOnOption ?? $this->defaultFailOn);

            if ($failOn === null) {
                $io->error(sprintf('Invalid --fail-on value "%s". Expected error, warning or notice.', $failOnOption));

                return Command::INVALID;
            }

            /** @var string|null $maxDepthOption */
            $maxDepthOption = $input->getOption('max-depth');
            $maxDepth = $maxDepthOption !== null ? (int)$maxDepthOption : null;

            /** @var list<string> $excludePatterns */
            $excludePatterns = (array)$input->getOption('exclude');

            $io->title('Technical SEO Audit');
            $io->text(sprintf('Starting crawl on: <info>%s</info>', $startUrl));
            $io->newLine();

            $progressBar = null;

            if (!$io->isVerbose()) {
                ProgressBar::setPlaceholderFormatterDefinition(
                    'truncated_url',
                    static fn(ProgressBar $bar): string => self::truncate((string)$bar->getMessage())
                );

                $progressBar = $io->createProgressBar();
                $progressBar->setFormat(' %current% pages audited [%elapsed%] <fg=cyan>%truncated_url%</>');
                $progressBar->setMessage('Starting...');
                $progressBar->start();
            }

            $progressCallback = static function (string $currentUrl, int $totalChecked, int $issuesCount) use (
                $io,
                $progressBar
            ): void {
                if ($io->isVerbose()) {
                    $status = $issuesCount > 0
                        ? sprintf('<fg=yellow>[%d ISSUE(S)]</>', $issuesCount)
                        : '<fg=green>[OK]</>';
                    $io->text(sprintf('%s (%d) %s', $status, $totalChecked, $currentUrl));
                } elseif ($progressBar !== null) {
                    $progressBar->setMessage($currentUrl);
                    $progressBar->advance();
                }
            };

            $report = $this->crawler->crawl(
                startUrl: $startUrl,
                maxDepth: $maxDepth,
                excludePatterns: $excludePatterns,
                progressCallback: $progressCallback,
            );

            if ($progressBar !== null) {
                $progressBar->finish();
                $io->newLine(2);
            } else {
                $io->newLine();
            }

            if (!$report->hasIssues()) {
                $io->success(
                    sprintf(
                        'All clear! Audited %d page(s) in %.2fs with 0 issues.',
                        $report->totalChecked,
                        $report->totalDuration
                    )
                );

                return Command::SUCCESS;
            }

            self::renderIssuesReport($io, $report);

            if (!$report->hasIssues($failOn)) {
                $io->success(
                    sprintf(
                        'No issue of severity "%s" or above. Audited %d page(s) in %.2fs.',
                        $failOn->value,
                        $report->totalChecked,
                        $report->totalDuration
                    )
                );

                return Command::SUCCESS;
            }

            $io->error(
                sprintf(
                    'Found %d issue(s) of severity "%s" or above across %d page(s) (Duration: %.2fs).',
                    $report->getIssuesCount($failOn),
                    $failOn->value,
                    $report->totalChecked,
                    $report->totalDuration
                )
            );

            return Command::FAILURE;
        } finally {
            $this->release();
        }
    }

    private static function renderIssuesReport(SymfonyStyle $io, TechnicalSeoReport $report): void
    {
        $counts = $report->getIssuesCountBySeverity();

        $io->section(
            sprintf(
                'Issues Found (%d): %d error(s), %d warning(s), %d notice(s)',
                $report->getIssuesCount(),
                $counts[Severity::Error->value],
                $counts[Severity::Warning->value],
                $counts[Severity::Notice->value],
            )
        );

        $table = $io->createTable();
        $table->setHeaders(['Severity', 'Page', 'Issue', 'Message']);
        $table->setStyle('box');

        $severityWidth = 8;
        $issueWidth = 24;
        $borderOverhead = 18;
        $available = max(45, (new Terminal())->getWidth() - $severityWidth - $issueWidth - $borderOverhead);

        $pageWidth = (int)round($available * 0.4);
        $messageWidth = (int)round($available * 0.6);

        $table->setColumnMaxWidth(1, $pageWidth);
        $table->setColumnMaxWidth(2, $issueWidth);
        $table->setColumnMaxWidth(3, $messageWidth);

        foreach ($report->pages as $page) {
            foreach ($page->issues as $issue) {
                $table->addRow([
                    self::formatSeverity($issue->severity()),
                    sprintf('<href=%s>%s</>', $page->url, self::truncate($page->url, $pageWidth)),
                    sprintf('<fg=yellow>%s</>', $issue->type->value),
                    self::truncate($issue->message, $messageWidth),
                ]);
            }
        }

        $table->render();
        $io->newLine();
    }

    private static function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => '<fg=red>error</>',
            Severity::Warning => '<fg=yellow>warning</>',
            Severity::Notice => '<fg=default>notice</>',
        };
    }

    private static function truncate(string $text, int $maxLength = 60): string
    {
        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength - 3, 'UTF-8').'...'
            : $text;
    }
}
