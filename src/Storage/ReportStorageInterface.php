<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Storage;

use Lbonnet\TechnicalSeoBundle\Model\TechnicalSeoReport;

interface ReportStorageInterface
{
    /**
     * Persists the report and returns its identifier or save path.
     */
    public function save(TechnicalSeoReport $report): string;
}
