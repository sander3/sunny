<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Location
    |--------------------------------------------------------------------------
    |
    | Set the install site's GPS via the env vars below. Open-Meteo panel
    | azimuth uses 0° = south, so a compass 225° SW facade becomes +45°.
    |
    */

    'latitude' => (float) env('AWNING_LATITUDE', 0.0),
    'longitude' => (float) env('AWNING_LONGITUDE', 0.0),
    'timezone' => env('AWNING_TIMEZONE', 'UTC'),
    'facade_tilt' => (float) env('AWNING_FACADE_TILT', 90.0),
    'facade_azimuth' => (float) env('AWNING_FACADE_AZIMUTH', 45.0),

    /*
    |--------------------------------------------------------------------------
    | Hardware
    |--------------------------------------------------------------------------
    |
    | Folding-arm awning dimensions and mounting height. The exposure factor
    | raises effective wind speed for more exposed / higher install sites.
    |
    */

    'width_m' => (float) env('AWNING_WIDTH_M', 5.5),
    'projection_m' => (float) env('AWNING_PROJECTION_M', 2.0),
    'floor' => (int) env('AWNING_FLOOR', 1),
    'wind_exposure_factor' => (float) env('AWNING_WIND_EXPOSURE_FACTOR', 1.15),

    /*
    |--------------------------------------------------------------------------
    | Glazing / heat gain
    |--------------------------------------------------------------------------
    |
    | Total shaded glazing area (m²) and its solar heat gain coefficient
    | (g-value). Modern HR++ double glazing is typically around 0.55.
    |
    */

    'glass_area_m2' => (float) env('AWNING_GLASS_AREA_M2', 7.2),
    'solar_heat_gain_coefficient' => (float) env('AWNING_SHGC', 0.55),

    /*
    |--------------------------------------------------------------------------
    | Comfort thresholds
    |--------------------------------------------------------------------------
    |
    | Well-insulated homes dump heat slowly, so shade early and keep shade
    | until solar gain clearly drops (hysteresis between open/close watts).
    |
    */

    'open_temperature_c' => (float) env('AWNING_OPEN_TEMPERATURE_C', 19.0),
    'close_temperature_c' => (float) env('AWNING_CLOSE_TEMPERATURE_C', 16.5),
    'open_heat_gain_w' => (float) env('AWNING_OPEN_HEAT_GAIN_W', 450.0),
    'close_heat_gain_w' => (float) env('AWNING_CLOSE_HEAT_GAIN_W', 220.0),
    'open_facade_irradiance_wm2' => (float) env('AWNING_OPEN_IRRADIANCE_WM2', 120.0),
    'close_facade_irradiance_wm2' => (float) env('AWNING_CLOSE_IRRADIANCE_WM2', 60.0),

    /*
    |--------------------------------------------------------------------------
    | Wind & precipitation safety
    |--------------------------------------------------------------------------
    |
    | Folding-arm awnings of this size typically retract around Beaufort 4–5.
    | Gust limits are stricter; reopen requires clear hysteresis. Safety close
    | always bypasses the comfort backoff.
    |
    */

    'max_wind_speed_kmh' => (float) env('AWNING_MAX_WIND_SPEED_KMH', 28.0),
    'max_wind_gust_kmh' => (float) env('AWNING_MAX_WIND_GUST_KMH', 42.0),
    'clear_wind_speed_kmh' => (float) env('AWNING_CLEAR_WIND_SPEED_KMH', 22.0),
    'clear_wind_gust_kmh' => (float) env('AWNING_CLEAR_WIND_GUST_KMH', 34.0),
    'max_precipitation_mm' => (float) env('AWNING_MAX_PRECIPITATION_MM', 0.2),
    'forecast_lookahead_minutes' => (int) env('AWNING_FORECAST_LOOKAHEAD_MINUTES', 60),

    /*
    | WMO weather-code ranges that force a safety retract regardless of comfort:
    | 65-67 heavy/freezing rain, 80-82 rain showers, 95-99 thunderstorms.
    */

    'severe_weather_code_ranges' => [
        [65, 67],
        [80, 82],
        [95, 99],
    ],

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    'min_state_change_minutes' => (int) env('AWNING_MIN_STATE_CHANGE_MINUTES', 45),
    'wind_recovery_minutes' => (int) env('AWNING_WIND_RECOVERY_MINUTES', 15),
    'weather_cache_seconds' => (int) env('AWNING_WEATHER_CACHE_SECONDS', 600),
    'state_cache_key' => 'awning:auto:state',
    'weather_cache_key' => 'awning:auto:weather',

];
