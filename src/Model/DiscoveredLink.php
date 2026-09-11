<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

final class DiscoveredLink
{
    public function __construct(
        public readonly string $url,
        public readonly bool $isExternal,
    ) {
    }
}
