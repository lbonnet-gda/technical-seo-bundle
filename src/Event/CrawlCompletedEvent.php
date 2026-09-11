<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Event;

use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;
use Symfony\Contracts\EventDispatcher\Event;

final class CrawlCompletedEvent extends Event
{
    public function __construct(
        public readonly TechnicalSeoReport $report,
    ) {
    }
}
