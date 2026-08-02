<?php

declare(strict_types=1);

namespace App\Awning;

use InvalidArgumentException;

readonly class AwningThresholds
{
    /**
     * @param  list<array{int, int}>  $severeWeatherCodeRanges
     */
    public function __construct(
        public float $openTemperatureC,
        public float $closeTemperatureC,
        public float $openHeatGainW,
        public float $closeHeatGainW,
        public float $maxWindSpeedKmh,
        public float $maxWindGustKmh,
        public float $squallGustKmh,
        public float $clearWindSpeedKmh,
        public float $clearWindGustKmh,
        public float $maxPrecipitationMm,
        public int $windLookaheadMinutes,
        public int $precipitationLookaheadMinutes,
        public int $minStateChangeMinutes,
        public int $windRecoveryMinutes,
        public array $severeWeatherCodeRanges,
    ) {
        $this->assertHysteresis('temperature', $this->closeTemperatureC, $this->openTemperatureC);
        $this->assertHysteresis('heat gain', $this->closeHeatGainW, $this->openHeatGainW);
        $this->assertClearBelowMax('wind speed', $this->clearWindSpeedKmh, $this->maxWindSpeedKmh);
        $this->assertClearBelowMax('wind gust', $this->clearWindGustKmh, $this->maxWindGustKmh);
        $this->assertSquallAboveMax();
        $this->assertPositive('wind lookahead minutes', $this->windLookaheadMinutes);
        $this->assertPositive('precipitation lookahead minutes', $this->precipitationLookaheadMinutes);
        $this->assertPositive('min state change minutes', $this->minStateChangeMinutes);
        $this->assertPositive('wind recovery minutes', $this->windRecoveryMinutes);
    }

    public static function fromConfig(): self
    {
        return new self(
            openTemperatureC: config()->float('awning.open_temperature_c'),
            closeTemperatureC: config()->float('awning.close_temperature_c'),
            openHeatGainW: config()->float('awning.open_heat_gain_w'),
            closeHeatGainW: config()->float('awning.close_heat_gain_w'),
            maxWindSpeedKmh: config()->float('awning.max_wind_speed_kmh'),
            maxWindGustKmh: config()->float('awning.max_wind_gust_kmh'),
            squallGustKmh: config()->float('awning.squall_gust_kmh'),
            clearWindSpeedKmh: config()->float('awning.clear_wind_speed_kmh'),
            clearWindGustKmh: config()->float('awning.clear_wind_gust_kmh'),
            maxPrecipitationMm: config()->float('awning.max_precipitation_mm'),
            windLookaheadMinutes: config()->integer('awning.wind_lookahead_minutes'),
            precipitationLookaheadMinutes: config()->integer('awning.precipitation_lookahead_minutes'),
            minStateChangeMinutes: config()->integer('awning.min_state_change_minutes'),
            windRecoveryMinutes: config()->integer('awning.wind_recovery_minutes'),
            severeWeatherCodeRanges: self::severeWeatherCodeRangesFromConfig(),
        );
    }

    public function isSevereWeatherCode(int $code): bool
    {
        foreach ($this->severeWeatherCodeRanges as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{int, int}>
     */
    private static function severeWeatherCodeRangesFromConfig(): array
    {
        $ranges = config()->array('awning.severe_weather_code_ranges');
        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || ! is_numeric($range[0] ?? null) || ! is_numeric($range[1] ?? null)) {
                throw new InvalidArgumentException('Each awning.severe_weather_code_ranges entry must be a [from, to] pair.');
            }

            $normalized[] = [(int) $range[0], (int) $range[1]];
        }

        return $normalized;
    }

    private function assertHysteresis(string $label, float $close, float $open): void
    {
        if ($close > $open) {
            throw new InvalidArgumentException(
                "Awning {$label} close threshold ({$close}) must not exceed the open threshold ({$open})."
            );
        }
    }

    private function assertClearBelowMax(string $label, float $clear, float $max): void
    {
        if ($clear > $max) {
            throw new InvalidArgumentException(
                "Awning {$label} clear threshold ({$clear}) must not exceed the safety maximum ({$max})."
            );
        }
    }

    private function assertSquallAboveMax(): void
    {
        if ($this->squallGustKmh < $this->maxWindGustKmh) {
            throw new InvalidArgumentException(
                "Awning squall gust threshold ({$this->squallGustKmh}) must be at least the safety maximum ({$this->maxWindGustKmh}); "
                .'a squall is the subset of gusts worth retracting early for.'
            );
        }
    }

    private function assertPositive(string $label, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("Awning {$label} must be greater than zero, got {$value}.");
        }
    }
}
