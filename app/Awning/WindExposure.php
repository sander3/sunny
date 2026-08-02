<?php

declare(strict_types=1);

namespace App\Awning;

use InvalidArgumentException;

/**
 * Scales Open-Meteo's 10 m reference wind up to the awning's mounting height.
 *
 * Wind speed grows with height following a power law, and 0.25 is the usual
 * exponent for built-up terrain. The height factor is clamped at 1.0 so a low
 * mounting point can only ever keep the forecast's safety margin, never erode
 * it; sheltering is far too site-specific to estimate from a floor number.
 */
readonly class WindExposure
{
    private const SHEAR_EXPONENT = 0.25;

    private const REFERENCE_HEIGHT_M = 10.0;

    public function __construct(
        public float $mountingHeightM,
        public float $siteFactor,
    ) {
        $this->assertPositive('mounting height', $this->mountingHeightM);
        $this->assertPositive('site exposure factor', $this->siteFactor);
    }

    /**
     * The awning hangs just under the ceiling of the floor it is mounted on,
     * so a ground-floor (0) install sits one storey height above the street.
     */
    public static function fromConfig(): self
    {
        $storeys = max(1, config()->integer('awning.floor') + 1);

        return new self(
            mountingHeightM: $storeys * config()->float('awning.metres_per_floor'),
            siteFactor: config()->float('awning.site_exposure_factor'),
        );
    }

    public function factor(): float
    {
        $shear = ($this->mountingHeightM / self::REFERENCE_HEIGHT_M) ** self::SHEAR_EXPONENT;

        return max(1.0, $shear) * $this->siteFactor;
    }

    public function apply(float $speedKmh): float
    {
        return $speedKmh * $this->factor();
    }

    private function assertPositive(string $label, float $value): void
    {
        if ($value <= 0.0) {
            throw new InvalidArgumentException("Awning {$label} must be greater than zero, got {$value}.");
        }
    }
}
