<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Awning\AwningAutoAction;
use App\Awning\AwningState;
use App\Awning\AwningStateRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AwningAutoTest extends TestCase
{
    private const DEVICES_URL = 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/setup/devices';

    private const EXEC_URL = 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'services.tahoma.host' => '192.168.1.42',
            'services.tahoma.port' => 8443,
            'services.tahoma.token' => 'test-token',
            'services.tahoma.device_url' => 'io://0817-9354-9770/15968589',
            'services.tahoma.verify_ssl' => false,
            'awning.wind_exposure_factor' => 1.0,
            'awning.glass_area_m2' => 7.2,
            'awning.solar_heat_gain_coefficient' => 0.55,
            'awning.open_temperature_c' => 19.0,
            'awning.close_temperature_c' => 16.5,
            'awning.open_heat_gain_w' => 450.0,
            'awning.close_heat_gain_w' => 220.0,
            'awning.open_facade_irradiance_wm2' => 120.0,
            'awning.close_facade_irradiance_wm2' => 60.0,
            'awning.max_wind_speed_kmh' => 28.0,
            'awning.max_wind_gust_kmh' => 42.0,
            'awning.clear_wind_speed_kmh' => 22.0,
            'awning.clear_wind_gust_kmh' => 34.0,
            'awning.max_precipitation_mm' => 0.2,
            'awning.min_state_change_minutes' => 45,
            'awning.weather_cache_seconds' => 60,
        ]);
    }

    public function test_auto_extends_the_awning_when_conditions_call_for_shade(): void
    {
        Http::fake([
            self::DEVICES_URL => Http::response([]),
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 26.0, irradiance: 400.0, windSpeed: 10.0, windGust: 16.0)),
            self::EXEC_URL => Http::response(['execId' => 'auto-open-exec']),
        ]);

        $this->artisan('awning:control', ['action' => 'auto'])
            ->expectsOutputToContain('Awning extend command sent (execution: auto-open-exec).')
            ->assertSuccessful();
    }

    public function test_auto_holds_when_already_in_the_desired_position(): void
    {
        app(AwningStateRepository::class)->save(new AwningState(
            position: AwningAutoAction::Extend,
            changedAt: CarbonImmutable::now()->subHours(2),
            reason: 'previous extend',
        ));

        Http::fake([
            self::DEVICES_URL => Http::response([]),
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 26.0, irradiance: 400.0, windSpeed: 10.0, windGust: 16.0)),
        ]);

        $this->artisan('awning:control', ['action' => 'auto'])
            ->expectsOutputToContain('Holding (extend).')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'exec/apply'));
    }

    public function test_auto_force_closes_on_unsafe_wind(): void
    {
        app(AwningStateRepository::class)->save(new AwningState(
            position: AwningAutoAction::Extend,
            changedAt: CarbonImmutable::now()->subMinutes(3),
            reason: 'recent extend',
        ));

        Http::fake([
            self::DEVICES_URL => Http::response([]),
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 28.0, irradiance: 450.0, windSpeed: 30.0, windGust: 50.0)),
            self::EXEC_URL => Http::response(['execId' => 'auto-wind-close']),
        ]);

        $this->artisan('awning:control', ['action' => 'auto'])
            ->expectsOutputToContain('Awning retract command sent (execution: auto-wind-close). Safety retract')
            ->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(float $temperature, float $irradiance, float $windSpeed, float $windGust): array
    {
        return [
            'current' => [
                'time' => '2026-07-19T15:00',
                'temperature_2m' => $temperature,
                'apparent_temperature' => $temperature,
                'precipitation' => 0.0,
                'rain' => 0.0,
                'wind_speed_10m' => $windSpeed,
                'wind_gusts_10m' => $windGust,
                'shortwave_radiation' => $irradiance,
                'direct_radiation' => $irradiance * 0.7,
                'global_tilted_irradiance' => $irradiance,
                'is_day' => 1,
                'cloud_cover' => 15,
                'weather_code' => 1,
            ],
            'minutely_15' => [
                'time' => ['2026-07-19T15:00', '2026-07-19T15:15'],
                'precipitation' => [0.0, 0.0],
                'wind_speed_10m' => [$windSpeed, $windSpeed],
                'wind_gusts_10m' => [$windGust, $windGust],
                'global_tilted_irradiance' => [$irradiance, $irradiance],
            ],
        ];
    }
}
