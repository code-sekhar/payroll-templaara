<?php

namespace App\Policies;

use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;

class AttendanceRegularizationRequestPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->activeCompany($company) && ($user->can('attendance_regularization.view') || $user->can('attendance_regularization.self_view'));
    }

    public function view(User $user, AttendanceRegularizationRequest $request): bool
    {
        if (app(ActiveCompany::class)->current()?->getKey() !== $request->company_id) {
            return false;
        }

        if ($user->can('attendance_regularization.view') && ($user->can('employee.view') || $this->visibleToManager($user, $request->employee))) {
            return true;
        }

        return $user->can('attendance_regularization.self_view')
            && (int) $request->employee?->user_id === (int) $user->id;
    }

    public function createForCompany(User $user, Company $company): bool
    {
        return $this->activeCompany($company) && $user->can('attendance_regularization.create');
    }

    public function createForEmployee(User $user, Company $company, Employee $employee): bool
    {
        if (! $this->activeCompany($company) || (int) $employee->company_id !== (int) $company->id || ! $user->can('attendance_regularization.create')) {
            return false;
        }

        return $user->can('employee.view') || $this->visibleToManager($user, $employee);
    }

    public function createSelf(User $user, Company $company, Employee $employee): bool
    {
        return $this->activeCompany($company)
            && (int) $employee->company_id === (int) $company->id
            && (int) $employee->user_id === (int) $user->id
            && $user->can('attendance_regularization.self_create');
    }

    public function cancel(User $user, AttendanceRegularizationRequest $request): bool
    {
        if (app(ActiveCompany::class)->current()?->getKey() !== $request->company_id || $request->status->value !== 'pending') {
            return false;
        }

        if ($user->can('attendance_regularization.cancel')
            && ($user->can('employee.view') || $this->visibleToManager($user, $request->employee))) {
            return true;
        }

        return $user->can('attendance_regularization.self_cancel')
            && (int) $request->requested_by === (int) $user->id
            && (int) $request->employee?->user_id === (int) $user->id;
    }

    public function approve(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $request->status->value === 'pending'
            && $this->canSubmitReview($user, $request, 'attendance_regularization.approve');
    }

    public function reject(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $request->status->value === 'pending'
            && $this->canSubmitReview($user, $request, 'attendance_regularization.reject');
    }

    public function submitApproval(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $this->canSubmitReview($user, $request, 'attendance_regularization.approve');
    }

    public function submitRejection(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $this->canSubmitReview($user, $request, 'attendance_regularization.reject');
    }

    private function canSubmitReview(User $user, AttendanceRegularizationRequest $request, string $permission): bool
    {
        if (app(ActiveCompany::class)->current()?->getKey() !== $request->company_id
            || (int) $request->requested_by === (int) $user->getKey()
            || ! $user->can($permission)) {
            return false;
        }

        return $user->can('employee.view') || $this->visibleToManager($user, $request->employee);
    }

    private function activeCompany(Company $company): bool
    {
        return app(ActiveCompany::class)->current()?->is($company) ?? false;
    }

    private function visibleToManager(User $user, ?Employee $employee): bool
    {
        if (! $employee || ! $user->can('employee.view_team')) {
            return false;
        }

        $managerIds = $user->employees()->where('company_id', $employee->company_id)->select('id');

        return Employee::query()->where('company_id', $employee->company_id)->whereKey($employee->id)
            ->where(fn (Builder $query): Builder => $query->whereIn('id', $managerIds)->orWhereIn('manager_id', $managerIds))
            ->exists();
    }
}
