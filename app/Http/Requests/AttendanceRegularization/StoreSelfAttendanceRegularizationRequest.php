<?php

namespace App\Http\Requests\AttendanceRegularization;

use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;

class StoreSelfAttendanceRegularizationRequest extends StoreAttendanceRegularizationRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $employee = $company instanceof Company
            ? $company->employees()->where('user_id', $this->user()?->id)->first()
            : null;

        return $company instanceof Company && $employee instanceof Employee
            && ($this->user()?->can('createSelf', [RegularizationRequest::class, $company, $employee]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['employee_id']);
        $rules['employee_id'] = ['prohibited'];

        return $rules;
    }
}
