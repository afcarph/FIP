<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * @OA\Tag(name="Profile", description="Account details, preferences and devices")
 */
class ProfileController extends Controller
{
    /**
     * @OA\Put(path="/profile", tags={"Profile"}, security={{"bearerAuth":{}}},
     *   summary="Update profile details", @OA\Response(response=200, description="Updated profile"))
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'email', 'max:180', Rule::unique('users')->ignore($user->getKey())],
            'home_city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'locale' => ['sometimes', 'string', 'in:en,fil'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Changing the address invalidates verification.
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $user->forceFill(['email_verified_at' => null])->save();
        }

        $user->update($data);

        return ApiResponse::success(new UserResource($user->fresh('preferences', 'company')), 'Profile updated.');
    }

    /**
     * @OA\Put(path="/profile/password", tags={"Profile"}, security={{"bearerAuth":{}}},
     *   summary="Change password",
     *   @OA\Response(response=200, description="Password changed"),
     *   @OA\Response(response=422, description="Current password incorrect"))
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        $user = $request->user();

        if ($user->password === null || ! Hash::check($request->string('current_password')->toString(), $user->password)) {
            return ApiResponse::error('invalid_current_password', 'Your current password is not correct.', 422);
        }

        $user->forceFill(['password' => $request->string('password')->toString()])->save();

        return ApiResponse::success(null, 'Password changed.');
    }

    /**
     * @OA\Put(path="/profile/preferences", tags={"Profile"}, security={{"bearerAuth":{}}},
     *   summary="Update notification and display preferences",
     *   @OA\Response(response=200, description="Preferences"))
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'preferred_fuel_type_id' => ['sometimes', 'nullable', 'integer', 'exists:fuel_types,id'],
            'price_alert_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'alert_radius_km' => ['sometimes', 'numeric', 'min:0.5', 'max:50'],
            'notify_price_alerts' => ['sometimes', 'boolean'],
            'notify_maintenance' => ['sometimes', 'boolean'],
            'notify_ai_insights' => ['sometimes', 'boolean'],
            'notify_marketing' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        $preferences = $request->user()->preferences()->updateOrCreate([], $data);

        return ApiResponse::success($preferences->toArray(), 'Preferences saved.');
    }

    /**
     * @OA\Post(path="/profile/biometric/enrol", tags={"Profile"}, security={{"bearerAuth":{}}},
     *   summary="Enrol a device public key for biometric sign-in",
     *   @OA\Response(response=200, description="Device enrolled"))
     */
    public function enrolBiometric(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'public_key' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ]);

        // Reject anything that is not a parsable public key before we store it.
        if (openssl_pkey_get_public($data['public_key']) === false) {
            return ApiResponse::error('invalid_public_key', 'That public key could not be parsed.', 422);
        }

        $request->user()->devices()->updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            [
                'biometric_key' => $data['public_key'],
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'],
                'is_trusted' => true,
                'last_seen_at' => now(),
            ],
        );

        $request->user()->forceFill(['biometric_enabled' => true])->save();

        return ApiResponse::success(null, 'Biometric sign-in enabled for this device.');
    }

    /**
     * @OA\Delete(path="/profile", tags={"Profile"}, security={{"bearerAuth":{}}},
     *   summary="Close the account (soft delete)", @OA\Response(response=204, description="Closed"))
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if ($user->password !== null && ! Hash::check($request->string('password')->toString(), $user->password)) {
            return ApiResponse::error('invalid_password', 'Password confirmation failed.', 422);
        }

        $user->update(['status' => 'suspended']);
        $user->delete();

        return ApiResponse::noContent();
    }
}
