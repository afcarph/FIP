<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @OA\Tag(name="Admin — Users", description="User, role and permission administration")
 */
class UserAdminController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionLimitService $limits,
    ) {}

    /**
     * @OA\Get(path="/admin/users", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="List users", @OA\Response(response=200, description="Paginated users"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        // Scoped to the caller's own tenant. The role filter is handed to the
        // repository rather than applied here: it used to be applied to a query
        // that was then discarded, so filtering by role returned everyone.
        $paginator = $this->users->paginateForActor(
            $request->user(),
            (int) $request->integer('per_page', 25),
            $request->only(['search', 'status', 'company_id', 'sort']),
            $request->has('role') ? $request->string('role')->toString() : null,
        );

        return ApiResponse::paginated($paginator, UserResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/admin/users", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="Create a user and assign roles", @OA\Response(response=201, description="Created"))
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $companyId = $this->companyForCreation($request->user(), $data['company_id'] ?? null);

        // Seats are counted against the company the user is being added to,
        // which is not necessarily the creator's own — a platform admin may be
        // adding somebody to a tenant they do not belong to.
        $this->limits->assertCompanyCanAdd($companyId, SubscriptionLimitService::SEATS);

        $user = $this->users->create(
            collect($data)->except('roles')->all() + ['company_id' => $companyId, 'status' => 'active'],
        );
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles($this->assignableRoles($request->user(), $data['roles']));
        $user->preferences()->create(['theme' => 'system']);

        return ApiResponse::created(new UserResource($user->load('roles', 'company')));
    }

    /**
     * @OA\Put(path="/admin/users/{user}", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="Update a user", @OA\Response(response=200, description="Updated"))
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'email' => ['sometimes', 'email', 'max:180', Rule::unique('users')->ignore($user->getKey())],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'status' => ['sometimes', 'string', 'in:active,pending,suspended,banned'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        // Moving a person between tenants is a platform action. Without this a
        // tenant admin could hand their own staff to another company, or annex
        // somebody else's by editing one field.
        if (array_key_exists('company_id', $data)
            && ! $request->user()->isPlatformAdministrator()
            && $data['company_id'] !== $user->company_id) {
            abort(403, 'Only a platform administrator may move a user between companies.');
        }

        $this->users->update($user, collect($data)->except('roles')->all());

        if (isset($data['roles'])) {
            $user->syncRoles($this->assignableRoles($request->user(), $data['roles']));
        }

        return ApiResponse::success(new UserResource($user->fresh('roles', 'company')));
    }

    /**
     * @OA\Delete(path="/admin/users/{user}", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="Deactivate a user", @OA\Response(response=204, description="Deactivated"))
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');

        $user->update(['status' => 'suspended']);
        $this->users->delete($user);

        return ApiResponse::noContent();
    }

    /**
     * @OA\Get(path="/admin/roles", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="Roles and their permissions", @OA\Response(response=200, description="Roles"))
     */
    public function roles(): JsonResponse
    {
        $this->authorize('manageRoles', User::class);

        return ApiResponse::success([
            'roles' => Role::with('permissions:id,name,group_name')->orderBy('level')->get()->map(static fn (Role $role) => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'label' => $role->label,
                'level' => $role->level,
                'permissions' => $role->permissions->pluck('name'),
            ])->all(),
            'permissions' => Permission::orderBy('group_name')->orderBy('name')->get(['id', 'name', 'group_name'])
                ->groupBy('group_name'),
        ]);
    }

    /**
     * @OA\Put(path="/admin/roles/{role}/permissions", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="Replace a role's permission set", @OA\Response(response=200, description="Updated"))
     */
    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        $this->authorize('manageRoles', User::class);

        abort_if($role->name === config('fip.roles.super_admin'), 422, 'The super administrator role cannot be edited.');

        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($request->input('permissions'));

        return ApiResponse::success([
            'role' => $role->name,
            'permissions' => $role->fresh()->permissions->pluck('name'),
        ], 'Permissions updated.');
    }

    /**
     * Guard against privilege escalation: only a super administrator may
     * grant the two platform-wide roles.
     */
    /**
     * The company a newly created user belongs to.
     *
     * A platform administrator may name any tenant, or none — operating across
     * tenants is their job. Anyone else creates inside their own company and
     * nowhere else: naming another is refused rather than quietly redirected,
     * because a silent redirect would hide an attempt worth seeing in the audit
     * trail. Omitting the field defaults to their own company rather than to
     * null, which used to create a companyless user while still charging their
     * company a seat.
     */
    private function companyForCreation(User $actor, ?int $requested): ?int
    {
        if ($actor->isPlatformAdministrator()) {
            return $requested;
        }

        if ($requested !== null && $requested !== $actor->company_id) {
            abort(403, 'You may only create users inside your own company.');
        }

        return $actor->company_id;
    }

    private function assignableRoles(User $actor, array $requested): array
    {
        if ($actor->hasRole(config('fip.roles.super_admin'))) {
            return $requested;
        }

        $restricted = [config('fip.roles.super_admin'), config('fip.roles.system_admin')];

        return array_values(array_diff($requested, $restricted));
    }
}
