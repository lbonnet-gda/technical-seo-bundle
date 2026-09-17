<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Sitemap;

final class ParsedSitemap
{
    public function __construct(
        public readonly bool $isIndex = false,
        public readonly array $locations = [],
        public readonly ?string $error = null,
        public readonly bool $tooManyEntries = false,
    ) {
    }
}
