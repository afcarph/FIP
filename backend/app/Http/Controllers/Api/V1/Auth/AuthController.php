<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\User\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\BiometricLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\MfaVerifyRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;

/**
 * @OA\Tag(name="Authentication", description="Registration, login, MFA and session lifecycle")
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * @OA\Post(
     *   path="/auth/register", tags={"Authentication"}, summary="Create an account",
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"first_name","last_name","email","password","password_confirmation"},
     *
     *     @OA\Property(property="first_name", type="string", example="Ella"),
     *     @OA\Property(property="last_name", type="string", example="Santos"),
     *     @OA\Property(property="email", type="string", format="email"),
     *     @OA\Property(property="password", type="string", format="password", minLength=12),
     *     @OA\Property(property="password_confirmation", type="string", format="password")
     *   )),
     *
     *   @OA\Response(response=201, description="Account created and signed in"),
     *   @OA\Response(response=409, description="Email already registered")
     * )
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $session = $this->auth->register($request->validated());

        return ApiResponse::created($this->presentSession($session), 'Welcome to FIP.');
    }

    /**
     * @OA\Post(
     *   path="/auth/login", tags={"Authentication"}, summary="Sign in with email and password",
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"email","password"},
     *
     *     @OA\Property(property="email", type="string", format="email"),
     *     @OA\Property(property="password", type="string", format="password"),
     *     @OA\Property(property="device", type="object")
     *   )),
     *
     *   @OA\Response(response=200, description="Signed in, or an MFA challenge"),
     *   @OA\Response(response=401, description="Invalid credentials"),
     *   @OA\Response(response=423, description="Account temporarily locked")
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->input('device'),
        );

        if ($result['status'] === 'mfa_required') {
            return ApiResponse::success($result, 'Enter the code from your authenticator app.');
        }

        return ApiResponse::success($this->presentSession($result), 'Signed in.');
    }

    /**
     * @OA\Post(
     *   path="/auth/mfa/verify", tags={"Authentication"}, summary="Complete an MFA challenge",
     *
     *   @OA\Response(response=200, description="Signed in")
     * )
     */
    public function verifyMfa(MfaVerifyRequest $request): JsonResponse
    {
        $session = $this->auth->verifyMfa(
            $request->string('challenge_token')->toString(),
            $request->string('code')->toString(),
            $request->input('device'),
        );

        return ApiResponse::success($this->presentSession($session), 'Signed in.');
    }

    /**
     * @OA\Post(
     *   path="/auth/biometric", tags={"Authentication"},
     *   summary="Sign in with a device-bound biometric key",
     *
     *   @OA\Response(response=200, description="Signed in")
     * )
     */
    public function biometric(BiometricLoginRequest $request): JsonResponse
    {
        $session = $this->auth->loginWithBiometric(
            $request->string('device_uuid')->toString(),
            $request->string('signature')->toString(),
            $request->string('nonce')->toString(),
        );

        return ApiResponse::success($this->presentSession($session), 'Signed in.');
    }

    /**
     * Issue the nonce a device must sign for biometric login. Single use and
     * short lived so a captured nonce cannot be replayed.
     */
    public function biometricChallenge(Request $request): JsonResponse
    {
        $nonce = bin2hex(random_bytes(32));

        cache()->put("biometric:nonce:{$request->string('device_uuid')}", $nonce, now()->addMinutes(2));

        return ApiResponse::success(['nonce' => $nonce, 'expires_in' => 120]);
    }

    /**
     * @OA\Get(path="/auth/{provider}/redirect", tags={"Authentication"},
     *   summary="Begin Google or Apple sign-in",
     *
     *   @OA\Parameter(name="provider", in="path", required=true, @OA\Schema(type="string", enum={"google","apple"})),
     *
     *   @OA\Response(response=200, description="Provider authorisation URL"))
     */
    public function socialRedirect(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['google', 'apple'], true), 404);

        return ApiResponse::success([
            'redirect_url' => Socialite::driver($provider)->stateless()->redirect()->getTargetUrl(),
        ]);
    }

    /**
     * @OA\Get(path="/auth/{provider}/callback", tags={"Authentication"},
     *   summary="Complete Google or Apple sign-in",
     *
     *   @OA\Response(response=200, description="Signed in"))
     */
    public function socialCallback(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['google', 'apple'], true), 404);

        $social = Socialite::driver($provider)->stateless()->user();

        $session = $this->auth->loginWithProvider($provider, [
            'id' => $social->getId(),
            'email' => $social->getEmail(),
            'name' => $social->getName(),
            'avatar' => $social->getAvatar(),
        ]);

        return ApiResponse::success($this->presentSession($session), 'Signed in.');
    }

    /**
     * @OA\Post(path="/auth/refresh", tags={"Authentication"}, security={{"bearerAuth":{}}},
     *   summary="Exchange a valid token for a fresh one",
     *
     *   @OA\Response(response=200, description="New access token"))
     */
    public function refresh(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->auth->refresh((string) $request->bearerToken()),
            'Token refreshed.',
        );
    }

    /**
     * @OA\Post(path="/auth/logout", tags={"Authentication"}, security={{"bearerAuth":{}}},
     *   summary="Invalidate the current token", @OA\Response(response=204, description="Signed out"))
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request->input('device_uuid'));

        return ApiResponse::noContent();
    }

    /**
     * @OA\Get(path="/auth/me", tags={"Authentication"}, security={{"bearerAuth":{}}},
     *   summary="Current user profile, roles and permissions",
     *
     *   @OA\Response(response=200, description="Profile"))
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('preferences', 'company', 'devices');

        return ApiResponse::success([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * @OA\Post(path="/auth/forgot-password", tags={"Authentication"},
     *   summary="Email a password reset link",
     *
     *   @OA\Response(response=200, description="Sent if the address exists"))
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:180']]);

        Password::sendResetLink($request->only('email'));

        // Always the same answer, so the endpoint cannot enumerate accounts.
        return ApiResponse::success(null, 'If that address is registered, a reset link is on its way.');
    }

    /**
     * @OA\Post(path="/auth/reset-password", tags={"Authentication"},
     *   summary="Complete a password reset",
     *
     *   @OA\Response(response=200, description="Password updated"),
     *   @OA\Response(response=422, description="Invalid or expired token"))
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill(['password' => $password, 'failed_login_attempts' => 0, 'locked_until' => null])->save();
            },
        );

        return $status === Password::PASSWORD_RESET
            ? ApiResponse::success(null, 'Password updated. You can sign in now.')
            : ApiResponse::error('reset_failed', 'That reset link is invalid or has expired.', 422);
    }

    /** Shape the session payload consistently across every sign-in path. */
    private function presentSession(array $session): array
    {
        return [
            'access_token' => $session['access_token'],
            'token_type' => $session['token_type'],
            'expires_in' => $session['expires_in'],
            'user' => new UserResource($session['user']),
            'roles' => $session['roles'],
            'permissions' => $session['permissions'],
        ];
    }
}
