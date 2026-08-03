<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Reporting\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Reports", description="Report generation and export")
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * @OA\Get(path="/reports/definitions", tags={"Reports"}, security={{"bearerAuth":{}}},
     *   summary="Reports the caller may run", @OA\Response(response=200, description="Definitions"))
     */
    public function definitions(Request $request): JsonResponse
    {
        $available = ReportDefinition::all()->filter(
            fn (ReportDefinition $d) => $d->required_permission === null || $request->user()->can($d->required_permission),
        )->values();

        return ApiResponse::success($available->all());
    }

    /**
     * @OA\Post(path="/reports/generate", tags={"Reports"}, security={{"bearerAuth":{}}},
     *   summary="Generate a report",
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"code"},
     *
     *     @OA\Property(property="code", type="string", example="fuel_expense_summary"),
     *     @OA\Property(property="format", type="string", enum={"pdf","xlsx","csv","json"}),
     *     @OA\Property(property="period", type="string", enum={"daily","weekly","monthly","annual"}),
     *     @OA\Property(property="from", type="string", format="date"),
     *     @OA\Property(property="to", type="string", format="date")
     *   )),
     *
     *   @OA\Response(response=202, description="Queued — poll the run for completion"),
     *   @OA\Response(response=201, description="Generated inline with a download URL"))
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'exists:report_definitions,code'],
            'format' => ['nullable', 'string', 'in:pdf,xlsx,csv,json'],
            'period' => ['nullable', 'string', 'in:daily,weekly,monthly,annual'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'integer'],
            'fleet_id' => ['nullable', 'integer'],
        ]);

        $run = $this->reports->request(
            $request->user(),
            $data['code'],
            collect($data)->except(['code', 'format'])->all(),
            $data['format'] ?? 'pdf',
        );

        return $run->status === ReportRun::STATUS_COMPLETED
            ? ApiResponse::created($this->present($run), 'Report ready.')
            : ApiResponse::success($this->present($run), 'Report queued.', 202);
    }

    /**
     * @OA\Get(path="/reports/runs", tags={"Reports"}, security={{"bearerAuth":{}}},
     *   summary="Report history", @OA\Response(response=200, description="Runs"))
     */
    public function runs(Request $request): JsonResponse
    {
        $paginator = ReportRun::query()
            ->where('requested_by', $request->user()->getKey())
            ->with('definition:id,code,name')
            ->latest()
            ->paginate(20);

        return ApiResponse::paginated($paginator);
    }

    /**
     * @OA\Get(path="/reports/runs/{run}", tags={"Reports"}, security={{"bearerAuth":{}}},
     *   summary="Report run status and download link",
     *
     *   @OA\Response(response=200, description="Run with a signed URL when complete"))
     */
    public function show(Request $request, ReportRun $run): JsonResponse
    {
        abort_unless($run->requested_by === $request->user()->getKey() || $request->user()->isPlatformAdministrator(), 403);

        return ApiResponse::success($this->present($run));
    }

    private function present(ReportRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'report' => $run->definition?->name,
            'code' => $run->definition?->code,
            'status' => $run->status,
            'format' => $run->format,
            'period' => [
                'from' => $run->period_start?->toDateString(),
                'to' => $run->period_end?->toDateString(),
            ],
            'row_count' => $run->row_count,
            'file_size' => $run->file_size,
            'download_url' => $run->status === ReportRun::STATUS_COMPLETED ? $run->downloadUrl() : null,
            'error_message' => $run->error_message,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }
}
