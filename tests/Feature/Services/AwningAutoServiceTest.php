<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Awning\AwningAutoAction;
use App\Awning\AwningState;
use App\Awning\AwningStateRepository;
use App\Services\AwningAutoService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AwningAutoServiceTest extends TestCase
{
    private const DEVICES_URL = 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/setup/devices';

    private const EXEC_URL = 'https://192.168.1.42:8443/enduser-mobile-web/1/enduserAPI/exec/apply';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 15:00:00', 'Europe/Amsterdam'));

        config([
            'services.tahoma.host' => '192.168.1.42',
            'services.tahoma.port' => 8443,
            'services.tahoma.token' => 'test-token',
            'services.tahoma.device_url' => 'io://0817-9354-9770/15968589',
            'services.tahoma.verify_ssl' => false,
            'awning.open_temperature_c' => 19.0,
            'awning.close_temperature_c' => 16.5,
            'awning.open_heat_gain_w' => 450.0,
            'awning.close_heat_gain_w' => 220.0,
            'awning.max_wind_speed_kmh' => 28.0,
            'awning.max_wind_gust_kmh' => 42.0,
            'awning.squall_gust_kmh' => 55.0,
            'awning.wind_lookahead_minutes' => 20,
            'awning.clear_wind_speed_kmh' => 22.0,
            'awning.clear_wind_gust_kmh' => 34.0,
            'awning.max_precipitation_mm' => 0.4,
            'awning.precipitation_lookahead_minutes' => 30,
            'awning.min_state_change_minutes' => 45,
            'awning.wind_recovery_minutes' => 15,
            'awning.floor' => 1,
            'awning.metres_per_floor' => 3.0,
            'awning.site_exposure_factor' => 1.0,
            'awning.glass_area_m2' => 7.2,
            'awning.solar_heat_gain_coefficient' => 0.55,
            'awning.weather_cache_seconds' => 60,
            'awning.forecast_lookahead_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_run_extends_and_persists_state(): void
    {
        Http::fake([
            self::DEVICES_URL => Http::response([]),
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 26.0, irradiance: 400.0, windGust: 16.0)),
            self::EXEC_URL => Http::response(['execId' => 'extend-exec']),
        ]);

        $result = app(AwningAutoService::class)->run();

        $this->assertSame(AwningAutoAction::Extend, $result->decision->action);
        $this->assertSame('extend-exec', $result->executionId);
        $this->assertSame(AwningAutoAction::Extend, app(AwningStateRepository::class)->get()->position);
    }

    public function test_wind_safety_close_ignores_backoff(): void
    {
        app(AwningStateRepository::class)->save(new AwningState(
            position: AwningAutoAction::Extend,
            changedAt: CarbonImmutable::now()->subMinutes(5),
            reason: 'recent extend',
        ));

        Http::fake([
            self::DEVICES_URL => Http::response([]),
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 27.0, irradiance: 400.0, windGust: 55.0)),
            self::EXEC_URL => Http::response(['execId' => 'wind-close-exec']),
        ]);

        $result = app(AwningAutoService::class)->run();

        $this->assertSame(AwningAutoAction::Retract, $result->decision->action);
        $this->assertSame('wind-close-exec', $result->executionId);
        $this->assertTrue($result->decision->bypassesBackoff);
        $this->assertNotNull(app(AwningStateRepository::class)->get()->windClosedAt);
    }

    public function test_it_reconciles_position_from_tahoma(): void
    {
        app(AwningStateRepository::class)->save(new AwningState(
            position: AwningAutoAction::Extend,
            changedAt: CarbonImmutable::now()->subHours(2),
            reason: 'cached extend',
        ));

        Http::fake([
            self::DEVICES_URL => Http::response([[
                'deviceURL' => 'io://0817-9354-9770/15968589',
                'states' => [['name' => 'core:OpenClosedState', 'value' => 'closed']],
            ]]),
            // Mild conditions: neither extend nor retract, so we can observe reconciliation alone.
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 22.0, irradiance: 100.0, windGust: 12.0)),
        ]);

        $result = app(AwningAutoService::class)->run();

        $this->assertSame(AwningAutoAction::Hold, $result->decision->action);
        $this->assertSame(AwningAutoAction::Retract, app(AwningStateRepository::class)->get()->position);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'exec/apply'));
    }

    public function test_an_external_move_is_protected_by_the_backoff(): void
    {
        app(AwningStateRepository::class)->save(new AwningState(
            position: AwningAutoAction::Extend,
            changedAt: CarbonImmutable::now()->subHours(2),
            reason: 'cached extend',
        ));

        Http::fake([
            self::DEVICES_URL => Http::response([[
                'deviceURL' => 'io://0817-9354-9770/15968589',
                'states' => [['name' => 'core:OpenClosedState', 'value' => 'closed']],
            ]]),
            // Hot and sunny: without the override lock this would re-extend at once.
            'api.open-meteo.com/*' => Http::response($this->payload(temperature: 26.0, irradiance: 400.0, windGust: 16.0)),
        ]);

        $result = app(AwningAutoService::class)->run();

        $this->assertSame(AwningAutoAction::Hold, $result->decision->action);
        $this->assertStringContainsString('Backoff active', $result->decision->reason);
        $this->assertSame(
            CarbonImmutable::now()->toIso8601String(),
            app(AwningStateRepository::class)->get()->changedAt?->toIso8601String(),
        );

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'exec/apply'));
    }

    public function test_record_manual_action_maps_command_to_domain_action(): void
    {
        app(AwningAutoService::class)->recordManualAction('open');

        $this->assertSame(AwningAutoAction::Extend, app(AwningStateRepository::class)->get()->position);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(float $temperature, float $irradiance, float $windGust, float $windSpeed = 12.0): array
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
