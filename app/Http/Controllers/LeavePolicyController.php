<?php

namespace App\Http\Controllers;

use App\Actions\LeavePolicies\ChangeLeavePolicyStatus;
use App\Actions\LeavePolicies\CreateLeavePolicy;
use App\Actions\LeavePolicies\DeleteLeavePolicy;
use App\Actions\LeavePolicies\UpdateLeavePolicy;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Http\Requests\LeavePolicy\StoreLeavePolicyRequest;
use App\Http\Requests\LeavePolicy\UpdateLeavePolicyRequest;
use App\Models\Company;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LeavePolicyController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', LeavePolicy::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $query = $activeCompany->leavePolicies()->with('leaveType:id,name,code')->orderBy('name');
        $search = $request->string('search')->trim()->toString();
        $status = LeavePolicyStatus::tryFrom($request->string('status')->toString());
        $accrualMethod = LeaveAccrualMethod::tryFrom($request->string('accrual_method')->toString());
        $leaveTypeId = $request->integer('leave_type_id');

        if ($search !== '') {
            $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhereHas('leaveType', fn (Builder $typeQuery) => $typeQuery->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
        }
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($accrualMethod !== null) {
            $query->where('accrual_method', $accrualMethod->value);
        }
        if ($leaveTypeId > 0) {
            $query->where('leave_type_id', $leaveTypeId);
        }

        return Inertia::render('leave-policies/index', [
            'policies' => $query->paginate(20)->withQueryString()->through(fn (LeavePolicy $leavePolicy): array => $this->leavePolicyData($leavePolicy)),
            'leaveTypes' => $activeCompany->leaveTypes()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (LeaveType $leaveType): array => ['id' => $leaveType->id, 'name' => $leaveType->name, 'code' => $leaveType->code])->values(),
            'filters' => ['search' => $search, 'status' => $status?->value ?? '', 'accrual_method' => $accrualMethod?->value ?? '', 'leave_type_id' => $leaveTypeId > 0 ? (string) $leaveTypeId : ''],
            'accrualMethods' => array_map(fn (LeaveAccrualMethod $value): array => ['value' => $value->value, 'label' => str($value->value)->title()->toString()], LeaveAccrualMethod::cases()),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $request->user()->can('leave_policy.create'),
            'canUpdate' => $request->user()->can('leave_policy.update'),
            'canDelete' => $request->user()->can('leave_policy.delete'),
            'canActivate' => $request->user()->can('leave_policy.activate'),
            'canDeactivate' => $request->user()->can('leave_policy.deactivate'),
        ]);
    }

    public function create(Company $company): InertiaResponse
    {
        Gate::authorize('create', LeavePolicy::class);
        $activeCompany = $this->assertSelectedCompany($company);

        return Inertia::render('leave-policies/form', [
            'policy' => null,
            'leaveTypes' => $activeCompany->leaveTypes()->where('status', LeaveTypeStatus::Active->value)->orderBy('name')->get(['id', 'name', 'code', 'allows_half_day']),
            'company' => $activeCompany->only('id', 'name'),
            'canActivate' => request()->user()->can('leave_policy.activate'),
            'canDeactivate' => request()->user()->can('leave_policy.deactivate'),
        ]);
    }

    public function store(StoreLeavePolicyRequest $request, Company $company, CreateLeavePolicy $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.leave-policies.index', $company)->with('success', 'Leave policy created.');
    }

    public function edit(Company $company, LeavePolicy $leavePolicy): InertiaResponse
    {
        Gate::authorize('update', $leavePolicy);
        $activeCompany = $this->assertSelectedCompany($company);
        $leaveTypes = $activeCompany->leaveTypes()
            ->where(function (Builder $query) use ($leavePolicy): void {
                $query->where('status', LeaveTypeStatus::Active->value)->orWhereKey($leavePolicy->leave_type_id);
            })->orderBy('name')->get(['id', 'name', 'code', 'allows_half_day', 'status']);

        return Inertia::render('leave-policies/form', [
            'policy' => $this->leavePolicyData($leavePolicy),
            'leaveTypes' => $leaveTypes,
            'company' => $activeCompany->only('id', 'name'),
            'canActivate' => request()->user()->can('leave_policy.activate'),
            'canDeactivate' => request()->user()->can('leave_policy.deactivate'),
        ]);
    }

    public function update(UpdateLeavePolicyRequest $request, Company $company, LeavePolicy $leavePolicy, UpdateLeavePolicy $update): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        $update->handle($leavePolicy, $request->user(), $request->validated());

        return to_route('companies.leave-policies.index', $company)->with('success', 'Leave policy updated.');
    }

    public function activate(Company $company, LeavePolicy $leavePolicy, ChangeLeavePolicyStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('activate', $leavePolicy);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($leavePolicy, request()->user(), LeavePolicyStatus::Active);

        return to_route('companies.leave-policies.index', $company)->with('success', 'Leave policy activated.');
    }

    public function deactivate(Company $company, LeavePolicy $leavePolicy, ChangeLeavePolicyStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('deactivate', $leavePolicy);
        $this->assertSelectedCompany($company);
        $changeStatus->handle($leavePolicy, request()->user(), LeavePolicyStatus::Inactive);

        return to_route('companies.leave-policies.index', $company)->with('success', 'Leave policy deactivated.');
    }

    public function destroy(Company $company, LeavePolicy $leavePolicy, DeleteLeavePolicy $delete): RedirectResponse
    {
        Gate::authorize('delete', $leavePolicy);
        $this->assertSelectedCompany($company);
        $delete->handle($leavePolicy, request()->user());

        return to_route('companies.leave-policies.index', $company)->with('success', 'Leave policy archived.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }

    /** @return array<string, mixed> */
    private function leavePolicyData(LeavePolicy $leavePolicy): array
    {
        return [
            'id' => $leavePolicy->id,
            'leave_type_id' => $leavePolicy->leave_type_id,
            'leave_type_name' => $leavePolicy->leaveType?->name,
            'leave_type_code' => $leavePolicy->leaveType?->code,
            'name' => $leavePolicy->name,
            'code' => $leavePolicy->code,
            'description' => $leavePolicy->description,
            'status' => $leavePolicy->status->value,
            'entitlement_days' => $leavePolicy->entitlement_days,
            'accrual_method' => $leavePolicy->accrual_method->value,
            'accrual_frequency' => $leavePolicy->accrual_frequency->value,
            'carry_forward_enabled' => $leavePolicy->carry_forward_enabled,
            'max_carry_forward_days' => $leavePolicy->max_carry_forward_days,
            'max_balance_days' => $leavePolicy->max_balance_days,
            'allow_negative_balance' => $leavePolicy->allow_negative_balance,
            'max_negative_balance_days' => $leavePolicy->max_negative_balance_days,
            'minimum_request_days' => $leavePolicy->minimum_request_days,
            'maximum_request_days' => $leavePolicy->maximum_request_days,
            'minimum_notice_days' => $leavePolicy->minimum_notice_days,
            'allows_half_day' => $leavePolicy->allows_half_day,
            'requires_reason' => $leavePolicy->requires_reason,
            'updated_at' => $leavePolicy->updated_at?->toDateTimeString(),
        ];
    }
}
