<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        if (! $company instanceof Company || ! app(ActiveCompany::class)->current()?->is($company)) {
            return false;
        }

        if (str_contains((string) $this->route()?->getName(), 'my-leave.')) {
            $employee = $company->employees()->where('user_id', $this->user()?->id)->first();

            return $employee !== null && $this->user()?->can('createSelf', [LeaveRequest::class, $company, $employee]);
        }

        $employee = $company->employees()->whereKey($this->input('employee_id'))->first();

        return $employee !== null && $this->user()?->can('createForEmployee', [LeaveRequest::class, $company, $employee]);
    }

    protected function prepareForValidation(): void
    {
        if (str_contains((string) $this->route()?->getName(), 'my-leave.')) {
            $this->merge(['employee_id' => null]);
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');
        $selfService = str_contains((string) $this->route()?->getName(), 'my-leave.');

        return [
            'employee_id' => $selfService ? ['prohibited'] : ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $company?->id)->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $company?->id)->where('status', 'active')->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', Rule::when($this->input('duration') === 'half_day', ['same:start_date'])],
            'duration' => ['required', Rule::in(['full_day', 'half_day'])],
            'reason' => ['nullable', 'string', 'min:3', 'max:2000'],
            'employee_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
