<?php

declare(strict_types=1);

namespace Tests\Feature\Awning;

use App\Awning\AwningAutoAction;
use App\Awning\AwningPolicy;
use App\Awning\AwningState;
use App\Awning\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AwningPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 15:00:00', 'Europe/Amsterdam'));

        config([
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
            'awning.wind_recovery_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_extends_when_sw_facade_heat_gain_is_high(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 24.0,
                'facadeIrradianceWm2' => 350.0,
                'estimatedHeatGainW' => 1386.0,
                'windSpeedKmh' => 12.0,
                'windGustKmh' => 18.0,
            ]),
            AwningState::empty(),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Extend, $decision->action);
        $this->assertFalse($decision->bypassesBackoff);
    }

    public function test_it_retracts_immediately_when_gusts_exceed_safety_limits(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 28.0,
                'facadeIrradianceWm2' => 500.0,
                'estimatedHeatGainW' => 1980.0,
                'windSpeedKmh' => 20.0,
                'windGustKmh' => 50.0,
            ]),
            new AwningState(position: AwningAutoAction::Extend, changedAt: CarbonImmutable::now()->subMinutes(2)),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Retract, $decision->action);
        $this->assertTrue($decision->bypassesBackoff);
        $this->assertStringContainsString('Safety retract', $decision->reason);
    }

    public function test_it_holds_during_comfort_backoff(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 24.0,
                'facadeIrradianceWm2' => 350.0,
                'estimatedHeatGainW' => 1386.0,
                'windSpeedKmh' => 10.0,
                'windGustKmh' => 15.0,
            ]),
            new AwningState(
                position: AwningAutoAction::Retract,
                changedAt: CarbonImmutable::now()->subMinutes(10),
                reason: 'manual retract',
            ),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Hold, $decision->action);
        $this->assertStringContainsString('Backoff active', $decision->reason);
    }

    public function test_it_retracts_at_night(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'isDay' => false,
                'temperatureC' => 18.0,
                'facadeIrradianceWm2' => 0.0,
                'estimatedHeatGainW' => 0.0,
            ]),
            new AwningState(position: AwningAutoAction::Extend, changedAt: CarbonImmutable::now()->subHours(2)),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Retract, $decision->action);
    }

    public function test_it_retracts_on_forecast_gusts_within_lookahead(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 26.0,
                'facadeIrradianceWm2' => 400.0,
                'estimatedHeatGainW' => 1584.0,
                'windSpeedKmh' => 15.0,
                'windGustKmh' => 20.0,
                'forecast' => [[
                    'at' => CarbonImmutable::now()->addMinutes(30),
                    'wind_speed_kmh' => 18.0,
                    'wind_gust_kmh' => 48.0,
                    'precipitation_mm' => 0.0,
                    'facade_irradiance_wm2' => 380.0,
                ]],
            ]),
            new AwningState(position: AwningAutoAction::Extend, changedAt: CarbonImmutable::now()->subHours(2)),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Retract, $decision->action);
        $this->assertStringContainsString('forecast gusts', $decision->reason);
    }

    public function test_it_allows_shorter_recovery_after_wind_close(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 26.0,
                'facadeIrradianceWm2' => 400.0,
                'estimatedHeatGainW' => 1584.0,
                'windSpeedKmh' => 12.0,
                'windGustKmh' => 18.0,
            ]),
            new AwningState(
                position: AwningAutoAction::Retract,
                changedAt: CarbonImmutable::now()->subMinutes(16),
                reason: 'Safety retract',
                windClosedAt: CarbonImmutable::now()->subMinutes(16),
            ),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Extend, $decision->action);
    }

    public function test_it_holds_when_shade_wanted_but_wind_not_yet_clear(): void
    {
        $decision = $this->policy()->decide(
            $this->snapshot([
                'temperatureC' => 26.0,
                'facadeIrradianceWm2' => 400.0,
                'estimatedHeatGainW' => 1584.0,
                'windSpeedKmh' => 25.0,
                'windGustKmh' => 36.0,
            ]),
            new AwningState(position: AwningAutoAction::Retract, changedAt: CarbonImmutable::now()->subHours(2)),
            CarbonImmutable::now(),
        );

        $this->assertSame(AwningAutoAction::Hold, $decision->action);
        $this->assertStringContainsString('wind still elevated', $decision->reason);
    }

    private function policy(): AwningPolicy
    {
        return app(AwningPolicy::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function snapshot(array $overrides = []): WeatherSnapshot
    {
        $facade = (float) ($overrides['facadeIrradianceWm2'] ?? 0.0);

        return new WeatherSnapshot(
            observedAt: CarbonImmutable::now(),
            temperatureC: (float) ($overrides['temperatureC'] ?? 20.0),
            apparentTemperatureC: (float) ($overrides['apparentTemperatureC'] ?? ($overrides['temperatureC'] ?? 20.0)),
            windSpeedKmh: (float) ($overrides['windSpeedKmh'] ?? 10.0),
            windGustKmh: (float) ($overrides['windGustKmh'] ?? 15.0),
            precipitationMm: (float) ($overrides['precipitationMm'] ?? 0.0),
            facadeIrradianceWm2: $facade,
            shortwaveRadiationWm2: (float) ($overrides['shortwaveRadiationWm2'] ?? $facade),
            directRadiationWm2: (float) ($overrides['directRadiationWm2'] ?? $facade * 0.7),
            cloudCoverPercent: (int) ($overrides['cloudCoverPercent'] ?? 20),
            weatherCode: (int) ($overrides['weatherCode'] ?? 1),
            isDay: (bool) ($overrides['isDay'] ?? true),
            estimatedHeatGainW: (float) ($overrides['estimatedHeatGainW'] ?? round(7.2 * 0.55 * $facade, 1)),
            forecast: $overrides['forecast'] ?? [],
        );
    }
}
