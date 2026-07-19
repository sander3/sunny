<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'Scheduler application. See routes/console.php for scheduled commands.';
});
