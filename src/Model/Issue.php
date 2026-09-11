<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Model;

final class Issue
{
    public function __construct(
        public readonly IssueType $type,
        public readonly string $message,
    ) {
    }

    public function severity(): Severity
    {
        return $this->type->severity();
    }
}
