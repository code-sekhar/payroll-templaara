<?php

namespace App\Http\Requests\WorkSchedule;

use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Enums\WorkScheduleStatus;
use App\Models\WorkSchedule;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class WorkScheduleRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function scheduleRules(?WorkSchedule $schedule, bool $includeStatus): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        $existingShiftIds = $schedule?->days()->whereNotNull('shift_id')->pluck('shift_id')->all() ?? [];

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('work_schedules', 'name')->where('company_id', $companyId)->ignore($schedule?->id)],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('work_schedules', 'code')->where('company_id', $companyId)->ignore($schedule?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'weekly_hours' => ['prohibited'],
            'status' => $includeStatus ? ['sometimes', Rule::enum(WorkScheduleStatus::class)] : ['prohibited'],
            'days' => ['required', 'array', 'size:7'],
            'days.*' => ['required', 'array'],
            'days.*.day_of_week' => ['required', 'integer', 'distinct', Rule::in(array_map(fn (DayOfWeek $day): int => $day->value, DayOfWeek::cases()))],
            'days.*.day_type' => ['required', Rule::enum(WorkScheduleDayType::class)],
            'days.*.shift_id' => [
                'nullable', 'integer',
                Rule::exists('shifts', 'id')->where('company_id', $companyId)->where(function ($query) use ($existingShiftIds): void {
                    $query->where('status', 'active')->orWhereIn('id', $existingShiftIds);
                }),
            ],
            'days.*.expected_minutes' => ['prohibited'],
            'days.*.company_id' => ['prohibited'],
            'days.*.work_schedule_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $days = $this->input('days');
            if (! is_array($days)) {
                return;
            }

            $dayNumbers = [];
            foreach ($days as $index => $day) {
                if (! is_array($day)) {
                    continue;
                }

                if (isset($day['day_of_week']) && is_numeric($day['day_of_week'])) {
                    $dayNumbers[] = (int) $day['day_of_week'];
                }

                $type = WorkScheduleDayType::tryFrom((string) ($day['day_type'] ?? ''));
                $hasShift = filled($day['shift_id'] ?? null);

                if ($type === WorkScheduleDayType::Off && $hasShift) {
                    $validator->errors()->add("days.{$index}.shift_id", 'Off days cannot have a shift.');
                }

                if (in_array($type, [WorkScheduleDayType::Working, WorkScheduleDayType::HalfDay], true) && ! $hasShift) {
                    $validator->errors()->add("days.{$index}.shift_id", 'Select a shift for working and half-day entries.');
                }
            }

            sort($dayNumbers);
            $expectedDays = array_map(fn (DayOfWeek $day): int => $day->value, DayOfWeek::cases());
            sort($expectedDays);

            if (count($days) !== 7 || $dayNumbers !== $expectedDays) {
                $validator->errors()->add('days', 'Configure each weekday exactly once.');
            }
        }];
    }
}
