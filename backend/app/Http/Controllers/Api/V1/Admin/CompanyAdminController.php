<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\User\Models\Company;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenants.
 *
 * Until this existed a company could only be created by editing the database
 * directly, which meant the first step of onboarding anybody had no API at all
 * — and `subscription_tier`, which every limit in the platform reads, could not
 * be set through the product that enforces it.
 *
 * There is no destroy. Deleting a tenant would orphan its users, vehicles,
 * drivers and devices, and `is_active` already expresses "stop using this
 * company" without destroying what it owns. Removal, if it is ever needed,
 * should be a considered operation with its own rules rather than a verb on a
 * CRUD controller.
 *
 * @OA\Tag(name="Admin — Companies", description="Tenant administration")
 */
class CompanyAdminController extends Controller
{
    public function __construct(private readonly SubscriptionLimitService $limits) {}

    /**
     * @OA\Get(path="/admin/companies", tags={"Admin — Companies"}, security={{"bearerAuth":{}}},
     *   summary="List tenants", @OA\Response(response=200, description="Paginated companies"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $paginator = Company::query()
            ->withCount(['users', 'vehicles', 'drivers'])
            ->when(
                isset($data['search']),
                fn ($q) => $q->where(function ($q) use ($data): void {
                    $q->where('name', 'like', '%'.$data['search'].'%')
                        ->orWhere('legal_name', 'like', '%'.$data['search'].'%');
                }),
            )
            ->when(isset($data['is_active']), fn ($q) => $q->where('is_active', $data['is_active']))
            ->orderBy('name')
            ->paginate((int) ($data['per_page'] ?? 25))
            ->withQueryString();

        return ApiResponse::paginated($paginator, CompanyResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/admin/companies", tags={"Admin — Companies"}, security={{"bearerAuth":{}}},
     *   summary="Create a tenant",
     *
     *   @OA\Response(response=201, description="Created"),
     *   @OA\Response(response=403, description="Not entitled to create companies"))
     */
    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        // The column defaults to `free`, but saying so explicitly means a
        // company created without a tier is on a plan somebody chose rather
        // than one it inherited from a schema default.
        $company = Company::create($request->validated() + [
            'subscription_tier' => $request->validated()['subscription_tier']
                ?? config('fip.subscription.default_tier'),
        ]);

        return ApiResponse::created(new CompanyResource($company));
    }

    /**
     * @OA\Get(path="/admin/companies/{company}", tags={"Admin — Companies"}, security={{"bearerAuth":{}}},
     *   summary="One tenant, with its usage against its plan",
     *
     *   @OA\Response(response=200, description="Company"))
     */
    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->loadCount(['users', 'vehicles', 'drivers']);

        // Usage against the plan is the answer to "why was I refused", so it
        // belongs on the tenant a manager can actually open.
        return ApiResponse::success(
            (new CompanyResource($company))->additional([
                'subscription' => $this->limits->describe($company->getKey(), $company->subscription_tier),
            ]),
        );
    }

    /**
     * @OA\Patch(path="/admin/companies/{company}", tags={"Admin — Companies"}, security={{"bearerAuth":{}}},
     *   summary="Update a tenant, including its subscription tier",
     *
     *   @OA\Response(response=200, description="Updated"),
     *   @OA\Response(response=403, description="Not entitled to edit companies"))
     */
    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        // Reducing a tier below current usage is allowed and deliberate: the
        // limits refuse new records without touching existing ones, so a
        // downgrade must never delete a vehicle somebody is driving.
        $company->loadCount(['users', 'vehicles', 'drivers']);

        return ApiResponse::success(
            (new CompanyResource($company->refresh()))->additional([
                'subscription' => $this->limits->describe($company->getKey(), $company->subscription_tier),
            ]),
        );
    }
}
