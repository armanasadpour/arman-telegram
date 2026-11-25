<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MySQL TIMESTAMP columns are converted through the session time zone. On a host whose MySQL runs on
 * Asia/Tehran, a UTC value that falls in an old daylight-saving gap (e.g. 2022-03-22 00:11) is rejected
 * ("Incorrect datetime value"). DATETIME columns store exactly what the panel writes (always UTC).
 *
 * convert() turns every TIMESTAMP column of the panel's tables into DATETIME. The ALTER runs with the
 * session time zone the data was written with, so the stored values do not move. Idempotent.
 */
class DatetimeColumns
{
    public static function convert(): int
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return 0;
        }
        $previous = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
        DB::statement('SET time_zone = @@global.time_zone');   // the zone existing rows were written in
        $n = 0;
        try {
            $cols = DB::select("SELECT TABLE_NAME t, COLUMN_NAME c, IS_NULLABLE n FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'");
            foreach ($cols as $col) {
                DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` DATETIME %s', str_replace('`', '', $col->t), str_replace('`', '', $col->c), $col->n === 'YES' ? 'NULL' : 'NOT NULL'));
                $n++;
            }
        } finally {
            DB::statement('SET time_zone = ?', [$previous]);
        }

        return $n;
    }
}
