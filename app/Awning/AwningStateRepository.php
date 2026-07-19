<?php

declare(strict_types=1);

namespace App\Awning;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class AwningStateRepository
{
    public function __construct(private readonly CacheRepository $cache) {}

    public function get(): AwningState
    {
        $raw = $this->cache->get($this->key(), []);

        return is_array($raw) ? AwningState::fromArray($raw) : AwningState::empty();
    }

    public function save(AwningState $state): void
    {
        $this->cache->forever($this->key(), $state->toArray());
    }

    /**
     * Persist a new position. A wind safety retract stamps windClosedAt so the
     * shorter recovery window applies; extending clears it; other retracts keep
     * whatever wind marker was already there.
     */
    public function record(AwningAutoAction $position, string $reason, bool $windClosed): AwningState
    {
        $previous = $this->get();
        $now = CarbonImmutable::now();

        $state = new AwningState(
            position: $position,
            changedAt: $now,
            reason: $reason,
            windClosedAt: $windClosed
                ? $now
                : ($position === AwningAutoAction::Extend ? null : $previous->windClosedAt),
        );

        $this->save($state);

        return $state;
    }

    private function key(): string
    {
        return config()->string('awning.state_cache_key');
    }
}
