<?php

declare(strict_types=1);

namespace App\Awning;

use Carbon\CarbonImmutable;

readonly class AwningState
{
    public function __construct(
        public ?AwningAutoAction $position = null,
        public ?CarbonImmutable $changedAt = null,
        public ?string $reason = null,
        public ?CarbonImmutable $windClosedAt = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<mixed, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            position: self::parseAction($state['position'] ?? null),
            changedAt: self::parseTimestamp($state['changed_at'] ?? null),
            reason: is_string($state['reason'] ?? null) ? $state['reason'] : null,
            windClosedAt: self::parseTimestamp($state['wind_closed_at'] ?? null),
        );
    }

    /**
     * @return array{position: ?string, changed_at: ?string, reason: ?string, wind_closed_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position?->value,
            'changed_at' => $this->changedAt?->toIso8601String(),
            'reason' => $this->reason,
            'wind_closed_at' => $this->windClosedAt?->toIso8601String(),
        ];
    }

    public function isAt(AwningAutoAction $action): bool
    {
        return $this->position === $action;
    }

    private static function parseAction(mixed $value): ?AwningAutoAction
    {
        return is_string($value) ? AwningAutoAction::tryFrom($value) : null;
    }

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
