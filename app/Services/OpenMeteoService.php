<?php

declare(strict_types=1);

namespace App\Services;

use App\Awning\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpenMeteoService
{
    /**
     * Safety-relevant fields (wind, gusts, precipitation, forecast) are fetched
     * fresh on every call so the every-minute schedule can react to a sudden
     * gust immediately. The heavier comfort fields (temperature, solar) change
     * slowly and are cached to keep the API load down.
     */
    public function currentForAwning(): WeatherSnapshot
    {
        $safety = $this->fetchSafetyPayload();

        $comfort = Cache::remember(
            config()->string('awning.weather_cache_key'),
            $this->positiveInt('awning.weather_cache_seconds'),
            fn (): array => $this->fetchComfortPayload(),
        );

        return $this->snapshotFrom($safety, $comfort);
    }

    /**
     * @return array<mixed, mixed>
     */
    public function fetchSafetyPayload(): array
    {
        $lookahead = $this->positiveInt('awning.forecast_lookahead_minutes');
        $minutelySlots = (int) ceil($lookahead / 15) + 1;

        return $this->get([
            'wind_speed_unit' => 'kmh',
            'forecast_minutely_15' => $minutelySlots,
            'current' => implode(',', [
                'precipitation',
                'rain',
                'wind_speed_10m',
                'wind_gusts_10m',
                'weather_code',
            ]),
            'minutely_15' => implode(',', [
                'precipitation',
                'wind_speed_10m',
                'wind_gusts_10m',
                'global_tilted_irradiance',
            ]),
        ]);
    }

    /**
     * @return array<mixed, mixed>
     */
    public function fetchComfortPayload(): array
    {
        return $this->get([
            'current' => implode(',', [
                'temperature_2m',
                'apparent_temperature',
                'shortwave_radiation',
                'direct_radiation',
                'global_tilted_irradiance',
                'is_day',
                'cloud_cover',
            ]),
        ]);
    }

    /**
     * @param  array<mixed, mixed>  $safety
     * @param  array<mixed, mixed>  $comfort
     */
    public function snapshotFrom(array $safety, array $comfort): WeatherSnapshot
    {
        $safetyCurrent = $this->currentSection($safety);
        $comfortCurrent = $this->currentSection($comfort);

        $facadeIrradiance = $this->numeric($comfortCurrent, 'global_tilted_irradiance');
        $glassArea = config()->float('awning.glass_area_m2');
        $shgc = config()->float('awning.solar_heat_gain_coefficient');
        $exposure = config()->float('awning.wind_exposure_factor');
        $timezone = config()->string('awning.timezone');

        return new WeatherSnapshot(
            observedAt: $this->observedAt($safetyCurrent, $timezone),
            temperatureC: $this->numeric($comfortCurrent, 'temperature_2m'),
            apparentTemperatureC: $this->numeric($comfortCurrent, 'apparent_temperature'),
            windSpeedKmh: $this->numeric($safetyCurrent, 'wind_speed_10m') * $exposure,
            windGustKmh: $this->numeric($safetyCurrent, 'wind_gusts_10m') * $exposure,
            precipitationMm: max(
                $this->numeric($safetyCurrent, 'precipitation'),
                $this->numeric($safetyCurrent, 'rain'),
            ),
            facadeIrradianceWm2: $facadeIrradiance,
            shortwaveRadiationWm2: $this->numeric($comfortCurrent, 'shortwave_radiation'),
            directRadiationWm2: $this->numeric($comfortCurrent, 'direct_radiation'),
            cloudCoverPercent: (int) round($this->numeric($comfortCurrent, 'cloud_cover')),
            weatherCode: (int) round($this->numeric($safetyCurrent, 'weather_code')),
            isDay: $this->numeric($comfortCurrent, 'is_day') === 1.0,
            estimatedHeatGainW: round($glassArea * $shgc * $facadeIrradiance, 1),
            forecast: $this->forecastFromPayload($safety, $exposure),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed, mixed>
     */
    protected function get(array $query): array
    {
        $response = $this->client()
            ->get('https://api.open-meteo.com/v1/forecast', array_merge([
                'latitude' => config()->float('awning.latitude'),
                'longitude' => config()->float('awning.longitude'),
                'timezone' => config()->string('awning.timezone'),
                'tilt' => config()->float('awning.facade_tilt'),
                'azimuth' => config()->float('awning.facade_azimuth'),
            ], $query))
            ->throw();

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Open-Meteo returned a non-array response.');
        }

        return $payload;
    }

    protected function client(): PendingRequest
    {
        return Http::retry([100, 300, 800], when: function (mixed $exception): bool {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError());
        })
            ->timeout(8)
            ->connectTimeout(3)
            ->acceptJson();
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return array<mixed, mixed>
     */
    protected function currentSection(array $payload): array
    {
        $current = $payload['current'] ?? null;

        if (! is_array($current)) {
            throw new RuntimeException('Open-Meteo response is missing current weather data.');
        }

        return $current;
    }

    /**
     * @param  array<mixed, mixed>  $current
     */
    protected function observedAt(array $current, string $timezone): CarbonImmutable
    {
        $raw = $current['time'] ?? null;

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Open-Meteo response is missing the observation timestamp.');
        }

        return CarbonImmutable::parse($raw, $timezone);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return list<array{at: CarbonImmutable, wind_speed_kmh: float, wind_gust_kmh: float, precipitation_mm: float, facade_irradiance_wm2: float}>
     */
    protected function forecastFromPayload(array $payload, float $exposure): array
    {
        $minutely = $payload['minutely_15'] ?? null;

        if (! is_array($minutely) || ! isset($minutely['time']) || ! is_array($minutely['time'])) {
            return [];
        }

        $timezone = config()->string('awning.timezone');
        $horizon = CarbonImmutable::now($timezone)->addMinutes($this->positiveInt('awning.forecast_lookahead_minutes'));
        $forecast = [];

        foreach ($minutely['time'] as $index => $time) {
            if (! is_string($time)) {
                continue;
            }

            $at = CarbonImmutable::parse($time, $timezone);

            if ($at->greaterThan($horizon)) {
                continue;
            }

            $forecast[] = [
                'at' => $at,
                'wind_speed_kmh' => $this->numericAt($minutely, 'wind_speed_10m', $index) * $exposure,
                'wind_gust_kmh' => $this->numericAt($minutely, 'wind_gusts_10m', $index) * $exposure,
                'precipitation_mm' => $this->numericAt($minutely, 'precipitation', $index),
                'facade_irradiance_wm2' => $this->numericAt($minutely, 'global_tilted_irradiance', $index),
            ];
        }

        return $forecast;
    }

    protected function positiveInt(string $key): int
    {
        $value = config()->integer($key);

        if ($value <= 0) {
            throw new InvalidArgumentException("Config [{$key}] must be greater than zero, got {$value}.");
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    protected function numeric(array $data, string $key): float
    {
        $value = $data[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    protected function numericAt(array $data, string $key, int|string $index): float
    {
        $series = $data[$key] ?? null;

        if (! is_array($series)) {
            return 0.0;
        }

        $value = $series[$index] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
