<?php

namespace App\Http\Requests\Company;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCompanyUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('user.create') && $this->user()?->can('user.assign_role')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', 'string', Rule::in(['hr_admin', 'hr_manager', 'manager', 'accountant', 'employee'])],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('email')) {
                return;
            }

            $user = User::query()->where('email', $this->string('email')->toString())->first();

            if (! $user?->email_verified_at) {
                $validator->errors()->add('email', 'Only an existing verified account can be added.');

                return;
            }

            $company = $this->route('company');

            if ($company->companyUsers()->where('user_id', $user->id)->exists()) {
                $validator->errors()->add('email', 'This account is already a member of the company.');
            }
        }];
    }
}
