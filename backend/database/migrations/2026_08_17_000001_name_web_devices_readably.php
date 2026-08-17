<?php

declare(strict_types=1);

use App\Support\Http\BrowserLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relabel web registrations that were stored under their User-Agent.
 *
 * Sign-in now derives a readable name from the request header, but rows
 * created before that still hold the raw string — and they are exactly the
 * rows a person would be looking at when trying to recognise a session.
 *
 * The label is computed from the string already stored, so this needs nothing
 * that was not already there. Rows whose name does not look like a User-Agent
 * are left alone: a handset called "Ramon's iPhone" is not this migration's
 * business, and neither is a browser row somebody has already renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('user_devices')
            ->where('platform', 'web')
            ->whereNotNull('device_name')
            // The marker every browser string carries, and nothing a person
            // would type. Matching on this rather than on "looks long" keeps
            // a deliberately chosen name safe.
            ->where('device_name', 'like', 'Mozilla/%')
            ->get(['id', 'device_name']);

        foreach ($rows as $row) {
            DB::table('user_devices')
                ->where('id', $row->id)
                ->update(['device_name' => BrowserLabel::from($row->device_name)]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. The User-Agent it was derived from is not
        // kept, and inventing one back would be worse than the label.
    }
};
