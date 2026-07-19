<?php

declare(strict_types=1);

namespace App\Providers;

use App\Awning\AwningThresholds;
use App\Database\Query\Grammars\SQLiteGrammar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AwningThresholds::class, static fn (): AwningThresholds => AwningThresholds::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::shouldBeStrict();

        Date::use(CarbonImmutable::class);

        $this->configureDatabasePrecision();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Store datetimes at the maximum precision the engine supports.
     *
     * By default Laravel stores second-precision datetimes; overriding the
     * SQLite query grammar's date format keeps full microsecond precision.
     */
    protected function configureDatabasePrecision(): void
    {
        $connection = DB::connection();

        if ($connection instanceof SQLiteConnection) {
            $connection->setQueryGrammar(new SQLiteGrammar($connection));
        }
    }
}
