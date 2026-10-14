<?php

namespace App\Http\Controllers;

use App\Actions\LeaveBalances\CloseLeaveBalanceAccount;
use App\Actions\LeaveBalances\CreateLeaveBalanceAccount;
use App\Actions\LeaveBalances\PostLeaveBalanceAdjustment;
use App\Actions\LeaveBalances\PostOpeningLeaveBalance;
use App\Enums\LeaveBalanceAccountStatus;
use App\Exceptions\Leave\LeavePeriodFinalizationException;
use App\Http\Requests\LeaveBalance\CloseLeaveBalanceAccountRequest;
use App\Http\Requests\LeaveBalance\IndexLeaveBalanceRequest;
use App\Http\Requests\LeaveBalance\StoreLeaveBalanceAccountRequest;
use App\Http\Requests\LeaveBalance\StoreLeaveBalanceAdjustmentRequest;
use App\Http\Requests\LeaveBalance\StoreOpeningLeaveBalanceRequest;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeLeaveBalanceController extends Controller
{
    public function __construct(private readonly ActiveCompany $activeCompany) {}

    public function index(IndexLeaveBalanceRequest $request, Company $company): InertiaResponse
    {
        Gate::authorize('viewAny', EmployeeLeaveBalanceAccount::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $filters = $request->validated();
        $today = now($activeCompany->timezone ?: config('app.timezone'))->toDateString();
        $query = $activeCompany->leaveBalanceAccounts()
            ->with(['employee:id,employee_code,first_name,middle_name,last_name', 'leaveType:id,name,code', 'leavePolicy:id,name,code', 'creator:id,name'])
            ->withSum(['ledgerEntries as balance_total' => fn (Builder $ledger): Builder => $ledger->whereDate('effective_date', '<=', $today)], 'amount')
            ->orderByDesc('period_start')->orderBy('employee_id')->orderBy('leave_type_id')->orderBy('id');

        if (! $request->user()->can('employee.view')) {
            $managerIds = $request->user()->employees()->where('company_id', $activeCompany->id)->select('id');
            $visibleIds = $activeCompany->employees()->where(fn (Builder $builder): Builder => $builder
                ->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds))->select('id');
            $query->whereIn('employee_id', $visibleIds);
        }

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn (Builder $builder): Builder => $builder
                ->whereHas('employee', fn (Builder $employee): Builder => $employee->where('employee_code', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('middle_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('leaveType', fn (Builder $type): Builder => $type->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))
                ->orWhereHas('leavePolicy', fn (Builder $policy): Builder => $policy->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
        }

        foreach (['leave_type_id', 'leave_policy_id', 'status'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (filled($filters['date'] ?? null)) {
            $date = $filters['date'];
            $query->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date);
        }
        if (filled($filters['department_id'] ?? null)) {
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('department_id', $filters['department_id']));
        }
        if (filled($filters['branch_id'] ?? null)) {
            $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('branch_id', $filters['branch_id']));
        }

        $user = $request->user();

        return Inertia::render('leave-balances/index', [
            'accounts' => $query->paginate(20)->withQueryString()->through(fn (EmployeeLeaveBalanceAccount $account): array => [
                'id' => $account->id,
                'employee' => ['id' => $account->employee_id, 'name' => $account->employee?->full_name ?? 'Archived employee', 'code' => $account->employee?->employee_code ?? '—'],
                'leave_type' => ['id' => $account->leave_type_id, 'name' => $account->leaveType?->name ?? 'Archived leave type', 'code' => $account->leaveType?->code ?? '—'],
                'leave_policy' => ['id' => $account->leave_policy_id, 'name' => $account->leavePolicy?->name ?? 'Archived policy', 'code' => $account->leavePolicy?->code ?? '—'],
                'period_start' => $account->period_start->toDateString(),
                'period_end' => $account->period_end->toDateString(),
                'balance' => LeaveAmount::fromCents(LeaveAmount::toCents((string) ($account->balance_total ?? '0'))),
                'status' => $account->status->value,
            ]),
            'filters' => array_merge(['search' => '', 'leave_type_id' => '', 'leave_policy_id' => '', 'status' => '', 'date' => '', 'department_id' => '', 'branch_id' => ''], $filters),
            'leaveTypes' => $activeCompany->leaveTypes()->orderBy('name')->get(['id', 'name', 'code']),
            'leavePolicies' => $activeCompany->leavePolicies()->orderBy('name')->get(['id', 'name', 'code', 'leave_type_id']),
            'departments' => $activeCompany->departments()->orderBy('name')->get(['id', 'name']),
            'branches' => $activeCompany->branches()->orderBy('name')->get(['id', 'name']),
            'company' => $activeCompany->only('id', 'name'),
            'canCreate' => $user->can('create', EmployeeLeaveBalanceAccount::class),
        ]);
    }

    public function create(Request $request, Company $company): InertiaResponse
    {
        Gate::authorize('create', EmployeeLeaveBalanceAccount::class);
        $activeCompany = $this->assertSelectedCompany($company);
        $search = $request->string('search')->trim()->limit(120)->toString();
        $assignments = $activeCompany->employeeLeavePolicyAssignments()
            ->with(['employee:id,employee_code,first_name,middle_name,last_name', 'leaveType:id,name', 'leavePolicy:id,name,code'])
            ->whereHas('employee', fn (Builder $employee): Builder => $employee->whereNull('deleted_at'))
            ->whereHas('leaveType', fn (Builder $type): Builder => $type->whereNull('deleted_at'))
            ->whereHas('leavePolicy', fn (Builder $policy): Builder => $policy->whereNull('deleted_at'))
            ->when($search !== '', fn (Builder $builder): Builder => $builder->whereHas('employee', fn (Builder $employee): Builder => $employee
                ->where('employee_code', 'like', '%'.$search.'%')->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('middle_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')))
            ->orderByDesc('effective_from')->limit(100)->get();

        return Inertia::render('leave-balances/create', [
            'company' => $activeCompany->only('id', 'name'),
            'assignments' => $assignments->map(fn (EmployeeLeavePolicyAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee' => $assignment->employee?->full_name ?? 'Archived employee',
                'employee_code' => $assignment->employee?->employee_code ?? '—',
                'leave_type' => $assignment->leaveType?->name ?? 'Archived type',
                'policy' => $assignment->leavePolicy?->name ?? 'Archived policy',
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
            ])->values(),
            'search' => $search,
        ]);
    }

    public function store(StoreLeaveBalanceAccountRequest $request, Company $company, CreateLeaveBalanceAccount $create): RedirectResponse
    {
        $activeCompany = $this->assertSelectedCompany($company);
        $account = $create->handle($activeCompany, $request->user(), $request->validated());

        return to_route('companies.leave-balances.show', [$company, $account])->with('success', 'Leave balance account created.');
    }

    public function show(Company $company, EmployeeLeaveBalanceAccount $leaveBalanceAccount, LeaveBalanceCalculator $calculator): InertiaResponse
    {
        Gate::authorize('view', $leaveBalanceAccount);
        $activeCompany = $this->assertSelectedCompany($company);
        $leaveBalanceAccount->load(['employee:id,employee_code,first_name,middle_name,last_name', 'leaveType', 'leavePolicy', 'assignment', 'creator:id,name', 'updater:id,name']);
        $today = now($activeCompany->timezone ?: config('app.timezone'))->toDateString();
        $balance = $calculator->calculate($leaveBalanceAccount, $today);
        $hasOpening = $leaveBalanceAccount->ledgerEntries()->where('transaction_type', 'opening_balance')->exists();
        $user = request()->user();

        return Inertia::render('leave-balances/show', [
            'company' => $activeCompany->only('id', 'name'),
            'account' => [
                'id' => $leaveBalanceAccount->id,
                'employee' => ['id' => $leaveBalanceAccount->employee_id, 'name' => $leaveBalanceAccount->employee?->full_name ?? 'Archived employee', 'code' => $leaveBalanceAccount->employee?->employee_code ?? '—'],
                'leave_type' => ['id' => $leaveBalanceAccount->leave_type_id, 'name' => $leaveBalanceAccount->leaveType?->name ?? 'Archived leave type', 'code' => $leaveBalanceAccount->leaveType?->code ?? '—'],
                'leave_policy' => ['id' => $leaveBalanceAccount->leave_policy_id, 'name' => $leaveBalanceAccount->leavePolicy?->name ?? 'Archived policy', 'code' => $leaveBalanceAccount->leavePolicy?->code ?? '—'],
                'assignment_id' => $leaveBalanceAccount->employee_leave_policy_assignment_id,
                'period_start' => $leaveBalanceAccount->period_start->toDateString(),
                'period_end' => $leaveBalanceAccount->period_end->toDateString(),
                'status' => $leaveBalanceAccount->status->value,
                'balance' => $balance->balance,
                'opening_balance' => $balance->openingBalance,
                'positive_adjustments' => $balance->positiveAdjustments,
                'negative_adjustments' => $balance->negativeAdjustments,
                'created_by' => $leaveBalanceAccount->creator?->name,
                'updated_by' => $leaveBalanceAccount->updater?->name,
            ],
            'ledger' => array_map(fn (array $row): array => [
                'id' => $row['entry']->id,
                'effective_date' => $row['entry']->effective_date->toDateString(),
                'type' => $row['entry']->transaction_type->value,
                'description' => $row['entry']->description,
                'amount' => (string) $row['entry']->amount,
                'running_balance' => $row['running_balance'],
                'created_by' => $row['entry']->creator?->name,
                'created_at' => $row['entry']->created_at?->toDateTimeString(),
            ], $calculator->runningBalanceHistory($leaveBalanceAccount)),
            'canPostOpening' => $leaveBalanceAccount->status === LeaveBalanceAccountStatus::Open && ! $hasOpening && $user->can('adjust', $leaveBalanceAccount),
            'canAdjust' => $leaveBalanceAccount->status === LeaveBalanceAccountStatus::Open && $user->can('adjust', $leaveBalanceAccount),
            'canClose' => $leaveBalanceAccount->status === LeaveBalanceAccountStatus::Open && $user->can('close', $leaveBalanceAccount),
        ]);
    }

    public function postOpening(StoreOpeningLeaveBalanceRequest $request, Company $company, EmployeeLeaveBalanceAccount $leaveBalanceAccount, PostOpeningLeaveBalance $post): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        Gate::authorize('adjust', $leaveBalanceAccount);
        $post->handle($leaveBalanceAccount, $request->user(), $request->validated());

        return back()->with('success', 'Opening balance posted.');
    }

    public function postAdjustment(StoreLeaveBalanceAdjustmentRequest $request, Company $company, EmployeeLeaveBalanceAccount $leaveBalanceAccount, PostLeaveBalanceAdjustment $post): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        Gate::authorize('adjust', $leaveBalanceAccount);
        $post->handle($leaveBalanceAccount, $request->user(), $request->validated());

        return back()->with('success', 'Leave balance adjustment posted.');
    }

    public function close(CloseLeaveBalanceAccountRequest $request, Company $company, EmployeeLeaveBalanceAccount $leaveBalanceAccount, CloseLeaveBalanceAccount $close): RedirectResponse
    {
        $this->assertSelectedCompany($company);
        try {
            $close->handle($leaveBalanceAccount, $request->user());
        } catch (LeavePeriodFinalizationException $exception) {
            throw ValidationException::withMessages(['account' => $exception->reason]);
        }

        return back()->with('success', 'Leave balance account closed.');
    }

    private function assertSelectedCompany(Company $company): Company
    {
        $activeCompany = $this->activeCompany->current();
        abort_unless($activeCompany?->is($company), 404);

        return $activeCompany;
    }
}
