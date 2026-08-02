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
    | Wind exposure
    |--------------------------------------------------------------------------
    |
    | Open-Meteo reports wind at a 10 m reference height, but wind speeds up
    | with height, so the mounting floor scales the reported figures. The site
    | factor is an extra manual margin for unusually exposed installs such as
    | corner plots, open water, or wind tunnels between blocks. Leave it at 1.0
    | unless the awning sees noticeably more wind than the forecast suggests.
    |
    */

    'floor' => (int) env('AWNING_FLOOR', 1),
    'metres_per_floor' => (float) env('AWNING_METRES_PER_FLOOR', 3.0),
    'site_exposure_factor' => (float) env('AWNING_SITE_EXPOSURE_FACTOR', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Glazing / heat gain
    |--------------------------------------------------------------------------
    |
    | Total shaded glazing area (m²) and its solar heat gain coefficient
    | (g-value). Modern HR++ double glazing is typically around 0.55. Together
    | these turn facade irradiance into the watts landing inside the room,
    | which is the signal the comfort thresholds below are expressed in.
    |
    */

    'glass_area_m2' => (float) env('AWNING_GLASS_AREA_M2', 7.2),
    'solar_heat_gain_coefficient' => (float) env('AWNING_SHGC', 0.55),

    /*
    |--------------------------------------------------------------------------
    | Comfort thresholds
    |--------------------------------------------------------------------------
    |
    | Shade is driven by estimated solar heat gain in watts, with hysteresis so
    | passing clouds cannot start a flap cycle. The defaults are roughly 225
    | W/m² on the facade to extend and 125 W/m² to retract, which is direct sun
    | on the glass rather than a bright overcast sky. Because the watts scale
    | with the glazing above, these stay meaningful if the glass area changes.
    |
    | The temperature pair is a floor, not a driver: it stops the awning
    | shading a cold room that happens to catch low winter sun.
    |
    */

    'open_temperature_c' => (float) env('AWNING_OPEN_TEMPERATURE_C', 19.0),
    'close_temperature_c' => (float) env('AWNING_CLOSE_TEMPERATURE_C', 16.5),
    'open_heat_gain_w' => (float) env('AWNING_OPEN_HEAT_GAIN_W', 900.0),
    'close_heat_gain_w' => (float) env('AWNING_CLOSE_HEAT_GAIN_W', 500.0),

    /*
    |--------------------------------------------------------------------------
    | Wind & precipitation safety
    |--------------------------------------------------------------------------
    |
    | Folding-arm awnings of this size typically retract around Beaufort 4–5.
    | Gust limits are stricter; reopen requires clear hysteresis. Safety close
    | always bypasses the comfort backoff.
    |
    | Forecasts are graded by how much a wrong call costs. A gust at the awning's
    | own limit only retracts once it is nearly here, because a 15-minute gust
    | peak an hour out is a coin flip and every early retract throws away a full
    | recovery cycle of shade. A squall-strength gust is worth coming in early
    | for even if it never lands, so it is watched across the whole horizon.
    |
    | Rain sits in between: arrival time forecasts better than gust magnitude,
    | and wet fabric is its own problem. The millimetre threshold sits above the
    | model's noise floor so a trace-precipitation blip does not retract.
    |
    */

    'max_wind_speed_kmh' => (float) env('AWNING_MAX_WIND_SPEED_KMH', 28.0),
    'max_wind_gust_kmh' => (float) env('AWNING_MAX_WIND_GUST_KMH', 42.0),
    'squall_gust_kmh' => (float) env('AWNING_SQUALL_GUST_KMH', 55.0),
    'clear_wind_speed_kmh' => (float) env('AWNING_CLEAR_WIND_SPEED_KMH', 22.0),
    'clear_wind_gust_kmh' => (float) env('AWNING_CLEAR_WIND_GUST_KMH', 34.0),
    'max_precipitation_mm' => (float) env('AWNING_MAX_PRECIPITATION_MM', 0.4),
    'forecast_lookahead_minutes' => (int) env('AWNING_FORECAST_LOOKAHEAD_MINUTES', 60),
    'wind_lookahead_minutes' => (int) env('AWNING_WIND_LOOKAHEAD_MINUTES', 20),
    'precipitation_lookahead_minutes' => (int) env('AWNING_PRECIPITATION_LOOKAHEAD_MINUTES', 30),

    /*
    | WMO weather-code ranges that force a safety retract regardless of comfort:
    | 56-57 freezing drizzle, 65-67 heavy/freezing rain, 71-77 snowfall, 80-82
    | rain showers, 85-86 snow showers, 95-99 thunderstorms.
    |
    | Frozen precipitation is named here rather than left to the millimetre
    | threshold above, because Open-Meteo reports precipitation as liquid water
    | equivalent. Steady light snow reads as a tenth of a millimetre while piling
    | centimetres of load onto the fabric, so it never trips the rain gate.
    */

    'severe_weather_code_ranges' => [
        [56, 57],
        [65, 67],
        [71, 77],
        [80, 82],
        [85, 86],
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
