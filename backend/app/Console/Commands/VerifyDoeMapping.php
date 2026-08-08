<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Doe\Services\DoeStationReference;
use App\Domain\Station\Models\GasStation;
use Illuminate\Console\Command;

class VerifyDoeMapping extends Command
{
    protected $signature = 'fip:verify-doe-mapping';

    protected $description = 'Report how many stations safely receive a DOE reference, and why the rest do not.';

    public function handle(DoeStationReference $doe): int
    {
        $matched = 0;
        $reasons = [];

        foreach (GasStation::query()->with(['brand', 'city.province.region'])->orderBy('id')->get() as $station) {
            $r = $doe->for($station);

            if ($r['matched']) {
                $matched++;
                $this->line(sprintf('  MATCHED   %-28s %-18s %s', substr($station->name, 0, 28), $r['doe_area'], count($r['prices']).' products'));

                continue;
            }

            $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + 1;
            $this->line(sprintf('  regional  %-28s %s', substr($station->name, 0, 28), $r['reason']));
        }

        $this->newLine();
        $this->line("  safely mapped: {$matched}");
        foreach ($reasons as $reason => $n) {
            $this->line("  {$reason}: {$n}");
        }

        return self::SUCCESS;
    }
}
