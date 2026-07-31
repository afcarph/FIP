<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\AiChatSession;
use App\Domain\Ai\Services\FuelAdvisorService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="AI Advisor", description="Conversational fuel assistant and decision helpers")
 */
class AssistantController extends Controller
{
    public function __construct(private readonly FuelAdvisorService $advisor) {}

    /**
     * @OA\Post(path="/assistant/chat", tags={"AI Advisor"}, security={{"bearerAuth":{}}},
     *   summary="Ask the fuel advisor a question",
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"question"},
     *     @OA\Property(property="question", type="string", example="Should I refuel today?"),
     *     @OA\Property(property="session_id", type="integer"),
     *     @OA\Property(property="latitude", type="number", format="float"),
     *     @OA\Property(property="longitude", type="number", format="float")
     *   )),
     *   @OA\Response(response=200, description="Grounded answer with sources and follow-ups"),
     *   @OA\Response(response=503, description="AI service unavailable"))
     */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'session_id' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $session = null;

        if (! empty($data['session_id'])) {
            $session = AiChatSession::where('user_id', $request->user()->getKey())
                ->findOrFail($data['session_id']);
        }

        return ApiResponse::success($this->advisor->ask(
            $request->user(),
            $data['question'],
            $session,
            array_filter([
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? null,
            ], static fn ($v) => $v !== null),
        ));
    }

    /**
     * @OA\Get(path="/assistant/should-i-refuel", tags={"AI Advisor"}, security={{"bearerAuth":{}}},
     *   summary="Deterministic refuel-now-or-wait recommendation",
     *   @OA\Response(response=200, description="Recommendation with peso impact"))
     */
    public function shouldRefuel(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
            'tank_level_pct' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        return ApiResponse::success($this->advisor->shouldRefuelToday(
            $request->user(),
            $request->has('vehicle_id') ? (int) $request->integer('vehicle_id') : null,
            $request->has('tank_level_pct') ? (float) $request->input('tank_level_pct') : null,
        ));
    }

    /**
     * @OA\Get(path="/assistant/consumption-explainer", tags={"AI Advisor"}, security={{"bearerAuth":{}}},
     *   summary="Explain a change in fuel consumption",
     *   @OA\Response(response=200, description="Attributed factors"))
     */
    public function explainConsumption(Request $request): JsonResponse
    {
        return ApiResponse::success($this->advisor->explainConsumptionChange(
            $request->user(),
            $request->has('vehicle_id') ? (int) $request->integer('vehicle_id') : null,
        ));
    }

    /**
     * @OA\Get(path="/assistant/sessions", tags={"AI Advisor"}, security={{"bearerAuth":{}}},
     *   summary="Chat history", @OA\Response(response=200, description="Sessions"))
     */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = $request->user()->chatSessions()
            ->withCount('messages')
            ->latest('last_message_at')
            ->limit(50)
            ->get(['id', 'title', 'last_message_at', 'token_usage']);

        return ApiResponse::success($sessions->all());
    }

    /**
     * @OA\Get(path="/assistant/sessions/{session}", tags={"AI Advisor"}, security={{"bearerAuth":{}}},
     *   summary="Transcript of one session", @OA\Response(response=200, description="Messages"))
     */
    public function transcript(Request $request, AiChatSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->getKey(), 403);

        return ApiResponse::success([
            'session' => ['id' => $session->getKey(), 'title' => $session->title],
            'messages' => $session->messages()->orderBy('id')->get(['role', 'content', 'created_at'])->all(),
        ]);
    }
}
