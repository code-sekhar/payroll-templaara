<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $hidden = ['settings'];

    protected $fillable = [
        'name', 'legal_name', 'slug', 'email', 'phone', 'address', 'city', 'state',
        'country', 'postal_code', 'timezone', 'currency', 'logo', 'status', 'employee_code_prefix', 'employee_code_next_number',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }

    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeInvitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function attendancePolicies(): HasMany
    {
        return $this->hasMany(AttendancePolicy::class);
    }

    public function attendanceWorksites(): HasMany
    {
        return $this->hasMany(AttendanceWorksite::class);
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }

    public function leavePolicies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function employeeLeavePolicyAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLeavePolicyAssignment::class);
    }

    public function leaveBalanceAccounts(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalanceAccount::class);
    }

    public function leaveLedgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
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

    public function employeeShiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function employeeWorkScheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkScheduleAssignment::class);
    }

    public function employeeHolidayCalendarAssignments(): HasMany
    {
        return $this->hasMany(EmployeeHolidayCalendarAssignment::class);
    }

    public function employeeAttendancePolicyAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAttendancePolicyAssignment::class);
    }

    public function attendancePolicyAssignments(): HasMany
    {
        return $this->employeeAttendancePolicyAssignments();
    }

    public function holidayCalendarAssignments(): HasMany
    {
        return $this->employeeHolidayCalendarAssignments();
    }

    public function workScheduleAssignments(): HasMany
    {
        return $this->employeeWorkScheduleAssignments();
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function holidayCalendars(): HasMany
    {
        return $this->hasMany(HolidayCalendar::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            if (blank($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });
    }
}
