<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Reporting\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Renders a large report off the request path and notifies the requester. */
class GenerateReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(private readonly int $reportRunId) {}

    public function handle(ReportService $reports, NotificationService $notifications): void
    {
        $run = ReportRun::with('definition', 'requester')->find($this->reportRunId);

        if ($run === null || $run->status === ReportRun::STATUS_COMPLETED) {
            return;
        }

        $reports->generate($run);

        if ($run->requester !== null) {
            $notifications->send($run->requester, 'report_ready', 'general', [
                'title' => 'Your report is ready',
                'body' => sprintf('%s (%s) has finished generating.', $run->definition?->name, strtoupper($run->format)),
                'data' => ['report_run_id' => $run->getKey()],
                'action_url' => "/reports/runs/{$run->getKey()}",
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Report generation failed', ['run_id' => $this->reportRunId, 'error' => $e->getMessage()]);

        ReportRun::where('id', $this->reportRunId)->update([
            'status' => ReportRun::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 500),
            'completed_at' => now(),
        ]);
    }
}
