<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use Database\Factories\EmployeeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'user_id', 'employee_code', 'first_name', 'middle_name', 'last_name',
        'date_of_birth', 'gender', 'marital_status', 'personal_email', 'personal_phone', 'profile_photo',
        'branch_id', 'department_id', 'designation_id', 'manager_id', 'joining_date', 'employment_type',
        'employment_status', 'address', 'city', 'state', 'country', 'postal_code', 'emergency_contact_name',
        'emergency_contact_phone', 'emergency_contact_relationship', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'employment_type' => EmploymentType::class,
            'employment_status' => EmploymentStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function workScheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkScheduleAssignment::class);
    }

    public function workScheduleAssignmentForDate(DateTimeInterface|string $date): ?EmployeeWorkScheduleAssignment
    {
        return $this->workScheduleAssignments()->effectiveOn($date)->with('workSchedule')->first();
    }

    public function currentWorkScheduleAssignment(): ?EmployeeWorkScheduleAssignment
    {
        $timezone = $this->company?->timezone ?: config('app.timezone');

        return $this->workScheduleAssignmentForDate(now($timezone)->toDateString());
    }

    public function employeeHolidayCalendarAssignments(): HasMany
    {
        return $this->hasMany(EmployeeHolidayCalendarAssignment::class);
    }

    public function attendancePolicyAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAttendancePolicyAssignment::class);
    }

    public function leavePolicyAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLeavePolicyAssignment::class);
    }

    public function leaveBalanceAccounts(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalanceAccount::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leavePolicyAssignmentForDate(LeaveType|int $leaveType, DateTimeInterface|string $date): ?EmployeeLeavePolicyAssignment
    {
        $leaveTypeId = $leaveType instanceof LeaveType ? $leaveType->id : $leaveType;

        return $this->leavePolicyAssignments()->where('company_id', $this->company_id)->where('leave_type_id', $leaveTypeId)
            ->effectiveOn($date)->with(['leaveType', 'leavePolicy'])->first();
    }

    public function currentLeavePolicyAssignment(LeaveType|int $leaveType): ?EmployeeLeavePolicyAssignment
    {
        $timezone = $this->company?->timezone ?: config('app.timezone');

        return $this->leavePolicyAssignmentForDate($leaveType, now($timezone)->toDateString());
    }

    /** @return Collection<int, EmployeeLeavePolicyAssignment> */
    public function currentLeavePolicyAssignments(): Collection
    {
        $timezone = $this->company?->timezone ?: config('app.timezone');

        return $this->leavePolicyAssignments()->where('company_id', $this->company_id)->effectiveOn(now($timezone)->toDateString())
            ->with(['leaveType', 'leavePolicy'])->orderBy('leave_type_id')->get();
    }

    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function dailyAttendanceRecords(): HasMany
    {
        return $this->hasMany(DailyAttendanceRecord::class);
    }

    public function attendanceRegularizationRequests(): HasMany
    {
        return $this->hasMany(AttendanceRegularizationRequest::class);
    }

    public function attendancePolicyAssignmentForDate(DateTimeInterface|string $date): ?EmployeeAttendancePolicyAssignment
    {
        return $this->attendancePolicyAssignments()->effectiveOn($date)->with('attendancePolicy')->first();
    }

    public function currentAttendancePolicyAssignment(): ?EmployeeAttendancePolicyAssignment
    {
        $timezone = $this->company?->timezone ?: config('app.timezone');

        return $this->attendancePolicyAssignmentForDate(now($timezone)->toDateString());
    }

    public function holidayCalendarAssignmentForDate(DateTimeInterface|string $date): ?EmployeeHolidayCalendarAssignment
    {
        return $this->employeeHolidayCalendarAssignments()->effectiveOn($date)->with('holidayCalendar')->first();
    }

    public function currentHolidayCalendarAssignment(): ?EmployeeHolidayCalendarAssignment
    {
        $timezone = $this->company?->timezone ?: config('app.timezone');

        return $this->holidayCalendarAssignmentForDate(now($timezone)->toDateString());
    }

    public function shiftAssignmentForDate(DateTimeInterface|string $date): ?EmployeeShiftAssignment
    {
        return $this->shiftAssignments()->effectiveOn($date)->with('shift')->first();
    }

    public function currentShiftAssignment(): ?EmployeeShiftAssignment
    {
        return $this->shiftAssignmentForDate(now($this->company->timezone ?? config('app.timezone'))->toDateString());
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }
}
