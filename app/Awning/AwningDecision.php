<?php

declare(strict_types=1);

namespace App\Awning;

readonly class AwningDecision
{
    public function __construct(
        public AwningAutoAction $action,
        public string $reason,
        public bool $bypassesBackoff = false,
    ) {}

    public function shouldCommand(): bool
    {
        return $this->action !== AwningAutoAction::Hold;
    }
}
