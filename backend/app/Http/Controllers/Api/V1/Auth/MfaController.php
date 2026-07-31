<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\User\Services\MfaService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="MFA", description="Time-based one-time password enrolment")
 */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    /**
     * @OA\Post(path="/auth/mfa/enrol", tags={"MFA"}, security={{"bearerAuth":{}}},
     *   summary="Start TOTP enrolment",
     *   @OA\Response(response=200, description="Secret and provisioning URI"))
     */
    public function enrol(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->mfa->beginEnrolment($request->user()),
            'Scan the QR code, then confirm with a code.',
        );
    }

    /**
     * @OA\Post(path="/auth/mfa/confirm", tags={"MFA"}, security={{"bearerAuth":{}}},
     *   summary="Confirm enrolment and receive recovery codes",
     *   @OA\Response(response=200, description="Recovery codes — shown once"))
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $codes = $this->mfa->confirmEnrolment($request->user(), $request->string('code')->toString());

        return ApiResponse::success(
            ['recovery_codes' => $codes],
            'Two-factor authentication is on. Store these recovery codes somewhere safe — they will not be shown again.',
        );
    }

    /**
     * @OA\Delete(path="/auth/mfa", tags={"MFA"}, security={{"bearerAuth":{}}},
     *   summary="Disable MFA", @OA\Response(response=204, description="Disabled"))
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $this->mfa->disable($request->user(), $request->string('code')->toString());

        return ApiResponse::noContent();
    }
}
