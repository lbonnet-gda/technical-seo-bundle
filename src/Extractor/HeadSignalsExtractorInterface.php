<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Extractor;

use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;

interface HeadSignalsExtractorInterface
{
    /**
     * @param string $html The HTML content of the page
     */
    public function extract(string $html): HeadSignals;
}
