<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @OA\Tag(name="Admin — Users", description="User, role and permission administration")
 */
class UserAdminController extends Controller
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * @OA\Get(path="/admin/users", tags={"Admin — Users"}, security={{"bearerAuth":{}}},
     *   summary="List users", @OA\Response(response=200, description="Paginated users"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = $this->users->query()->with('company:id,name', 'roles:id,name,label');

        if ($request->has('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')->toString()));
        }

        $paginator = $this->users->paginate(
            (int) $request->integer('per_page', 25),
            $request->only(['search', 'status', 'company_id', 'sort']),
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
            'password' => ['required', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user = $this->users->create(collect($data)->except('roles')->all() + ['status' => 'active']);
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
    private function assignableRoles(User $actor, array $requested): array
    {
        if ($actor->hasRole(config('fip.roles.super_admin'))) {
            return $requested;
        }

        $restricted = [config('fip.roles.super_admin'), config('fip.roles.system_admin')];

        return array_values(array_diff($requested, $restricted));
    }
}
