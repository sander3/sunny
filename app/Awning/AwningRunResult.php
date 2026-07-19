<?php

declare(strict_types=1);

namespace App\Awning;

readonly class AwningRunResult
{
    public function __construct(
        public AwningDecision $decision,
        public WeatherSnapshot $weather,
        public ?string $executionId = null,
        public ?AwningAutoAction $position = null,
    ) {}

    public function commandSent(): bool
    {
        return $this->executionId !== null;
    }
}
