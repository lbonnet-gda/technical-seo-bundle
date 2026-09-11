<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Auditor;

use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\Issue;
use Lbonnet\TechnicalSeoBundle\Model\PageResponse;

interface PageAuditorInterface
{
    /**
     * Audits the signals of a single page, using nothing but that page's own response.
     *
     * @return list<Issue>
     */
    public function audit(PageResponse $response, HeadSignals $signals): array;
}
