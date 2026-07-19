<?php

declare(strict_types=1);

namespace App\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\SQLiteGrammar as BaseSQLiteGrammar;

class SQLiteGrammar extends BaseSQLiteGrammar
{
    /**
     * Get the format for database stored dates.
     *
     * Store the maximum precision the engine supports (microseconds) so that
     * every persisted datetime keeps full precision. See:
     * https://carbon.nesbot.com/guide/getting-started/laravel.html#time-precision
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }
}
