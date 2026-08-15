<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Date expressions that work on every connection this application runs on.
 *
 * The application runs on MySQL and the test suite runs on SQLite in memory.
 * `DATE_FORMAT` exists only on the first, so any query using it was untestable
 * — it did not fail in a way that showed up as a missing test, it failed as a
 * 500 the moment a test touched the endpoint, which is why those endpoints
 * simply had no tests at all.
 *
 * That gap is not academic: it hid a key collision in the fleet dashboard
 * payload that every service-level test passed straight through.
 */
final class SqlDate
{
    /**
     * A `YYYY-MM` grouping key for the given column.
     *
     * The column name is interpolated into raw SQL, so it must always be a
     * literal from the calling code and never anything a request supplied.
     * Every caller today passes a constant.
     */
    public static function yearMonth(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }
}
