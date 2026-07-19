<?php

declare(strict_types=1);

namespace App\Awning;

use Carbon\CarbonImmutable;

readonly class WeatherSnapshot
{
    /**
     * @param  list<array{at: CarbonImmutable, wind_speed_kmh: float, wind_gust_kmh: float, precipitation_mm: float, facade_irradiance_wm2: float}>  $forecast
     */
    public function __construct(
        public CarbonImmutable $observedAt,
        public float $temperatureC,
        public float $apparentTemperatureC,
        public float $windSpeedKmh,
        public float $windGustKmh,
        public float $precipitationMm,
        public float $facadeIrradianceWm2,
        public float $shortwaveRadiationWm2,
        public float $directRadiationWm2,
        public int $cloudCoverPercent,
        public int $weatherCode,
        public bool $isDay,
        public float $estimatedHeatGainW,
        public array $forecast = [],
    ) {}
}
