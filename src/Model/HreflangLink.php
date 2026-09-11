<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

use Lbonnet\TechnicalSeoBundle\Hreflang\HreflangValidator;

final class HreflangLink
{
    /**
     * @param string $hreflang the hreflang value as authored
     * @param string $href the href as authored, not resolved
     */
    public function __construct(
        public readonly string $hreflang,
        public readonly string $href,
    ) {
    }

    public function isXDefault(): bool
    {
        return strcasecmp($this->hreflang, HreflangValidator::X_DEFAULT) === 0;
    }
}
