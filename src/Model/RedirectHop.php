<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

final class RedirectHop
{
    /**
     * @param string $url the URL that answered with a redirect
     * @param int $statusCode the 3xx status it answered with
     * @param string $location the absolute URL it pointed to
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly string $location,
    ) {
    }
}
