<?php

declare(strict_types=1);

namespace App\Awning;

use ValueError;

enum AwningAutoAction: string
{
    case Extend = 'extend';
    case Retract = 'retract';
    case Hold = 'hold';

    /**
     * Map the domain action onto the raw TaHoma command verb. For an awning,
     * extending the fabric out for shade is the device's "open" command.
     */
    public function tahomaCommand(): string
    {
        return match ($this) {
            self::Extend => 'open',
            self::Retract => 'close',
            self::Hold => throw new ValueError('Hold does not map to a TaHoma command.'),
        };
    }

    /**
     * Past participle for human-readable state descriptions ("Already retracted").
     */
    public function pastParticiple(): string
    {
        return match ($this) {
            self::Extend => 'extended',
            self::Retract => 'retracted',
            self::Hold => 'held',
        };
    }

    /**
     * Translate a raw TaHoma/manual command verb into a domain action.
     */
    public static function fromTahomaCommand(string $command): self
    {
        return match ($command) {
            'open' => self::Extend,
            'close' => self::Retract,
            default => throw new ValueError("Unknown TaHoma command [{$command}]."),
        };
    }
}
