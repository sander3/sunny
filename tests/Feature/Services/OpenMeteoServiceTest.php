<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\OpenMeteoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenMeteoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'awning.latitude' => 52.0,
            'awning.longitude' => 5.0,
            'awning.timezone' => 'UTC',
            'awning.facade_tilt' => 90.0,
            'awning.facade_azimuth' => 45.0,
            'awning.glass_area_m2' => 7.2,
            'awning.solar_heat_gain_coefficient' => 0.55,
            'awning.wind_exposure_factor' => 1.0,
            'awning.weather_cache_seconds' => 600,
            'awning.forecast_lookahead_minutes' => 60,
        ]);
    }

    public function test_it_builds_a_weather_snapshot_with_heat_gain(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->forecastPayload()),
        ]);

        $snapshot = app(OpenMeteoService::class)->currentForAwning();

        $this->assertSame(24.5, $snapshot->temperatureC);
        $this->assertSame(350.0, $snapshot->facadeIrradianceWm2);
        $this->assertEqualsWithDelta(7.2 * 0.55 * 350.0, $snapshot->estimatedHeatGainW, 0.1);
        $this->assertTrue($snapshot->isDay);
        $this->assertNotEmpty($snapshot->forecast);

        // First call fetches both the fresh safety payload and the comfort payload.
        Http::assertSentCount(2);
    }

    public function test_it_refetches_safety_data_but_caches_comfort_data(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->forecastPayload()),
        ]);

        $service = app(OpenMeteoService::class);
        $service->currentForAwning();
        $service->currentForAwning();

        // Two safety fetches (never cached) + one comfort fetch (cached after the first).
        Http::assertSentCount(3);
    }

    public function test_it_applies_wind_exposure_for_an_exposed_site(): void
    {
        config(['awning.wind_exposure_factor' => 1.15]);

        $snapshot = app(OpenMeteoService::class)->snapshotFrom($this->forecastPayload(), $this->forecastPayload());

        $this->assertEqualsWithDelta(20.0 * 1.15, $snapshot->windSpeedKmh, 0.01);
        $this->assertEqualsWithDelta(32.0 * 1.15, $snapshot->windGustKmh, 0.01);
    }

    public function test_it_fails_loudly_on_non_positive_cache_configuration(): void
    {
        config(['awning.weather_cache_seconds' => 0]);

        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->forecastPayload()),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(OpenMeteoService::class)->currentForAwning();
    }

    /**
     * @return array<string, mixed>
     */
    protected function forecastPayload(): array
    {
        return [
            'current' => [
                'time' => '2026-07-19T15:00',
                'temperature_2m' => 24.5,
                'apparent_temperature' => 25.0,
                'precipitation' => 0.0,
                'rain' => 0.0,
                'wind_speed_10m' => 20.0,
                'wind_gusts_10m' => 32.0,
                'shortwave_radiation' => 600.0,
                'direct_radiation' => 400.0,
                'global_tilted_irradiance' => 350.0,
                'is_day' => 1,
                'cloud_cover' => 20,
                'weather_code' => 1,
            ],
            'minutely_15' => [
                'time' => [
                    '2026-07-19T15:00',
                    '2026-07-19T15:15',
                    '2026-07-19T15:30',
                ],
                'precipitation' => [0.0, 0.0, 0.0],
                'wind_speed_10m' => [20.0, 21.0, 22.0],
                'wind_gusts_10m' => [32.0, 33.0, 34.0],
                'global_tilted_irradiance' => [350.0, 340.0, 330.0],
            ],
        ];
    }
}
