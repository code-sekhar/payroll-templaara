<?php

namespace App\Http\Controllers;

use App\Actions\LeaveTypes\ChangeLeaveTypeStatus;
use App\Actions\LeaveTypes\CreateLeaveType;
use App\Actions\LeaveTypes\DeleteLeaveType;
use App\Actions\LeaveTypes\UpdateLeaveType;
use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Http\Requests\LeaveType\StoreLeaveTypeRequest;
use App\Http\Requests\LeaveType\UpdateLeaveTypeRequest;
use App\Models\Company;
use App\Models\LeaveType;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LeaveTypeController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', LeaveType::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->leaveTypes()->withCount('policies')->orderBy('sort_order')->orderBy('name');
        $search = $request->string('search')->trim()->toString();
        $category = LeaveTypeCategory::tryFrom($request->string('category')->toString());
        $status = LeaveTypeStatus::tryFrom($request->string('status')->toString());

        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        if ($category !== null) {
            $query->where('category', $category->value);
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return Inertia::render('leave-types/index', [
            'leaveTypes' => $query->paginate(20)->withQueryString()->through(fn (LeaveType $leaveType): array => $this->leaveTypeData($leaveType)),
            'filters' => ['search' => $search, 'category' => $category?->value ?? '', 'status' => $status?->value ?? ''],
            'categories' => array_map(fn (LeaveTypeCategory $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], LeaveTypeCategory::cases()),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $request->user()->can('leave_type.create'),
            'canUpdate' => $request->user()->can('leave_type.update'),
            'canDelete' => $request->user()->can('leave_type.delete'),
            'canActivate' => $request->user()->can('leave_type.activate'),
            'canDeactivate' => $request->user()->can('leave_type.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', LeaveType::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('leave-types/form', [
            'leaveType' => null,
            'categories' => array_map(fn (LeaveTypeCategory $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], LeaveTypeCategory::cases()),
            'company' => $activeCompany->only('id', 'name'),
            'canActivate' => request()->user()->can('leave_type.activate'),
            'canDeactivate' => request()->user()->can('leave_type.deactivate'),
        ]);
    }

    public function store(StoreLeaveTypeRequest $request, Company $company, CreateLeaveType $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.leave-types.index', $company)->with('success', 'Leave type created.');
    }

    public function edit(Company $company, LeaveType $leaveType): InertiaResponse
    {
        Gate::authorize('update', $leaveType);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('leave-types/form', [
            'leaveType' => $this->leaveTypeData($leaveType),
            'categories' => array_map(fn (LeaveTypeCategory $value): array => ['value' => $value->value, 'label' => str($value->value)->replace('_', ' ')->title()->toString()], LeaveTypeCategory::cases()),
            'company' => $activeCompany->only('id', 'name'),
            'canActivate' => request()->user()->can('leave_type.activate'),
            'canDeactivate' => request()->user()->can('leave_type.deactivate'),
        ]);
    }

    public function update(UpdateLeaveTypeRequest $request, Company $company, LeaveType $leaveType, UpdateLeaveType $update): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        $update->handle($leaveType, $request->user(), $request->validated());

        return to_route('companies.leave-types.index', $company)->with('success', 'Leave type updated.');
    }

    public function activate(Company $company, LeaveType $leaveType, ChangeLeaveTypeStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $leaveType);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($leaveType, request()->user(), LeaveTypeStatus::Active);

        return to_route('companies.leave-types.index', $company)->with('success', 'Leave type activated.');
    }

    public function deactivate(Company $company, LeaveType $leaveType, ChangeLeaveTypeStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $leaveType);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($leaveType, request()->user(), LeaveTypeStatus::Inactive);

        return to_route('companies.leave-types.index', $company)->with('success', 'Leave type deactivated.');
    }

    public function destroy(Company $company, LeaveType $leaveType, DeleteLeaveType $delete): RedirectResponse
    {
        Gate::authorize('delete', $leaveType);
        $this->assertSelectedCompany($company);
        $delete->handle($leaveType, request()->user());

        return to_route('companies.leave-types.index', $company)->with('success', 'Leave type archived.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @return array<string, mixed> */
    private function leaveTypeData(LeaveType $leaveType): array
    {
        return [
            'id' => $leaveType->id,
            'name' => $leaveType->name,
            'code' => $leaveType->code,
            'description' => $leaveType->description,
            'category' => $leaveType->category->value,
            'is_paid' => $leaveType->is_paid,
            'allows_half_day' => $leaveType->allows_half_day,
            'status' => $leaveType->status->value,
            'sort_order' => $leaveType->sort_order,
            'policies_count' => $leaveType->policies_count ?? null,
        ];
    }
}
