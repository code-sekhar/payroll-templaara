<?php

use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function attendanceLeaveRecordAttributes(Company $company, Employee $employee, array $overrides = []): array
{
    return array_merge([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => '2026-10-08',
        'status' => 'present',
        'record_state' => 'final',
        'calculated_at' => now('UTC'),
    ], $overrides);
}

it('enforces the corrected Leave ownership mapping on Attendance and history while retaining nullable references', function (): void {
    $company = Company::factory()->create();
    $leaveRequest = LeaveRequest::factory()->create([
        'company_id' => $company->getKey(),
    ]);
    $employee = Employee::query()->findOrFail($leaveRequest->employee_id);
    $leaveDay = LeaveRequestDay::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_request_id' => $leaveRequest->getKey(),
    ]);

    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => '2026-10-08',
        'leave_request_id' => $leaveRequest->getKey(),
        'leave_request_day_id' => $leaveDay->getKey(),
        'leave_type_id' => $leaveRequest->leave_type_id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ]);

    DB::table('daily_attendance_record_histories')->insert([
        'company_id' => $company->getKey(),
        'daily_attendance_record_id' => $record->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => DB::table('daily_attendance_records')->where('id', $record->getKey())->value('attendance_date'),
        'status' => 'present',
        'record_state' => 'final',
        'expected_minutes' => 0,
        'worked_minutes' => 0,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'is_late' => false,
        'is_early_leave' => false,
        'calculation_version' => 1,
        'calculated_at' => now('UTC'),
        'change_reason' => 'initial_materialization',
        'actor_id' => null,
        'created_at' => now('UTC'),
        'leave_request_id' => $leaveRequest->getKey(),
        'leave_request_day_id' => null,
        'leave_type_id' => $leaveRequest->leave_type_id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ]);
    $invalidHistoryDay = (array) DB::table('daily_attendance_record_histories')
        ->where('daily_attendance_record_id', $record->getKey())
        ->first();
    unset($invalidHistoryDay['id']);
    $invalidHistoryDay['leave_request_day_id'] = 900004;

    expect(fn () => DB::table('daily_attendance_record_histories')->insert($invalidHistoryDay))
        ->toThrow(QueryException::class);

    $invalidHistoryType = (array) DB::table('daily_attendance_record_histories')
        ->where('daily_attendance_record_id', $record->getKey())
        ->first();
    unset($invalidHistoryType['id']);
    $invalidHistoryType['leave_type_id'] = LeaveType::factory()->create(['company_id' => $company->getKey()])->getKey();

    expect(fn () => DB::table('daily_attendance_record_histories')->insert($invalidHistoryType))
        ->toThrow(QueryException::class);

    $withoutLeaveHistory = (array) DB::table('daily_attendance_record_histories')
        ->where('daily_attendance_record_id', $record->getKey())
        ->first();
    unset($withoutLeaveHistory['id']);
    $withoutLeaveHistory['leave_request_id'] = null;
    $withoutLeaveHistory['leave_request_day_id'] = null;
    $withoutLeaveHistory['leave_type_id'] = null;
    DB::table('daily_attendance_record_histories')->insert($withoutLeaveHistory);

    $withoutLeave = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => '2026-10-09',
    ]);

    $leaveRequestForeignKey = collect(DB::select("PRAGMA foreign_key_list('daily_attendance_records')"))
        ->contains(fn (object $key): bool => $key->table === 'leave_requests' && $key->from === 'leave_request_id' && $key->to === 'id');

    expect($record->fresh()->leave_request_id)->toBe($leaveRequest->getKey())
        ->and(DB::table('daily_attendance_record_histories')->where('daily_attendance_record_id', $record->getKey())->value('leave_request_id'))->toBe($leaveRequest->getKey())
        ->and($withoutLeave->leave_request_id)->toBeNull()
        ->and($leaveRequestForeignKey)->toBeTrue();
});

it('rejects nonexistent and misowned Leave Request references in both Attendance tables', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $leaveRequest = LeaveRequest::factory()->create(['company_id' => $company->getKey()]);
    $employee = Employee::query()->findOrFail($leaveRequest->employee_id);
    $otherEmployeeRequest = LeaveRequest::factory()->create(['company_id' => $company->getKey()]);
    $otherEmployee = Employee::query()->findOrFail($otherEmployeeRequest->employee_id);
    $foreignRequest = LeaveRequest::factory()->create(['company_id' => $otherCompany->getKey()]);
    $otherLeaveType = LeaveType::factory()->create(['company_id' => $company->getKey()]);

    $invalidRecords = [
        attendanceLeaveRecordAttributes($company, $employee, ['leave_request_id' => 900001, 'leave_type_id' => $leaveRequest->leave_type_id]),
        attendanceLeaveRecordAttributes($company, $employee, ['leave_request_id' => $otherEmployeeRequest->getKey(), 'leave_type_id' => $otherEmployeeRequest->leave_type_id]),
        attendanceLeaveRecordAttributes($company, $employee, ['leave_request_id' => $leaveRequest->getKey(), 'leave_type_id' => $otherLeaveType->getKey()]),
        attendanceLeaveRecordAttributes($company, $employee, ['leave_request_id' => $foreignRequest->getKey(), 'leave_type_id' => $leaveRequest->leave_type_id]),
    ];

    foreach ($invalidRecords as $attributes) {
        expect(fn () => DB::table('daily_attendance_records')->insert($attributes))
            ->toThrow(QueryException::class);
    }

    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
    ]);

    foreach ([
        ['leave_request_id' => 900002, 'leave_type_id' => $leaveRequest->leave_type_id],
        ['leave_request_id' => $otherEmployeeRequest->getKey(), 'leave_type_id' => $otherEmployeeRequest->leave_type_id],
        ['leave_request_id' => $leaveRequest->getKey(), 'leave_type_id' => $otherLeaveType->getKey()],
    ] as $invalidReference) {
        expect(fn () => DB::table('daily_attendance_record_histories')->insert([
            'company_id' => $company->getKey(),
            'daily_attendance_record_id' => $record->getKey(),
            'employee_id' => $employee->getKey(),
            'attendance_date' => DB::table('daily_attendance_records')->where('id', $record->getKey())->value('attendance_date'),
            'status' => 'present',
            'record_state' => 'final',
            'expected_minutes' => 0,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'is_late' => false,
            'is_early_leave' => false,
            'calculation_version' => 1,
            'calculated_at' => now('UTC'),
            'change_reason' => 'initial_materialization',
            'actor_id' => null,
            'created_at' => now('UTC'),
            'leave_request_id' => $invalidReference['leave_request_id'],
            'leave_request_day_id' => null,
            'leave_type_id' => $invalidReference['leave_type_id'],
            'leave_amount' => '1.00',
            'leave_is_paid' => true,
        ]))->toThrow(QueryException::class);
    }
});

it('retains the independent Leave day and Leave type ownership constraints and Attendance integrity triggers', function (): void {
    $company = Company::factory()->create();
    $leaveRequest = LeaveRequest::factory()->create(['company_id' => $company->getKey()]);
    $employee = Employee::query()->findOrFail($leaveRequest->employee_id);
    $wrongLeaveType = LeaveType::factory()->create(['company_id' => $company->getKey()]);
    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_request_id' => $leaveRequest->getKey(),
        'leave_type_id' => $leaveRequest->leave_type_id,
    ]);

    expect(fn () => DB::table('daily_attendance_records')->where('id', $record->getKey())->update([
        'leave_request_day_id' => 900003,
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('daily_attendance_records')->where('id', $record->getKey())->update([
            'leave_type_id' => $wrongLeaveType->getKey(),
        ]))->toThrow(QueryException::class)
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', [
            'attendance_punches_prevent_update',
            'attendance_punches_prevent_delete',
            'leave_request_days_no_update',
            'leave_request_days_no_delete',
        ])->count())->toBe(4)
        ->and(DB::table('sqlite_master')->where('type', 'index')->whereIn('name', [
            'dar_company_employee_date_unique',
            'dar_company_id_id_unique',
            'dar_company_date_status_index',
            'dar_company_state_date_index',
            'darh_company_employee_date_created_index',
            'darh_record_created_index',
        ])->count())->toBe(6)
        ->and(DB::table('daily_attendance_records')->where('id', $record->getKey())->exists())->toBeTrue();
});

it('rebuilds SQLite foreign keys without losing table triggers', function (): void {
    foreach ([
        ['daily_attendance_records', ['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'], 'dar_leave_request_ownership_foreign'],
        ['daily_attendance_record_histories', ['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'], 'darh_leave_request_ownership_foreign'],
    ] as [$tableName, $correctColumns, $constraintName]) {
        $incorrectColumns = ['company_id', 'employee_id', 'leave_request_id', 'leave_type_id'];

        Schema::table($tableName, function (Blueprint $table) use ($correctColumns, $incorrectColumns, $constraintName): void {
            $table->dropForeign($correctColumns);
            $table->foreign($incorrectColumns, $constraintName)
                ->references(['company_id', 'id', 'employee_id', 'leave_type_id'])
                ->on('leave_requests')
                ->restrictOnDelete();
        });
    }

    DB::unprepared('CREATE TRIGGER ep5_c81_record_test_trigger AFTER INSERT ON daily_attendance_records BEGIN SELECT 1; END');
    DB::unprepared('CREATE TRIGGER ep5_c81_history_test_trigger AFTER INSERT ON daily_attendance_record_histories BEGIN SELECT 1; END');

    $migration = require database_path('migrations/2026_10_09_080621_repair_attendance_leave_request_ownership_foreign_keys.php');
    $migration->up();

    $recordColumns = collect(DB::select("PRAGMA foreign_key_list('daily_attendance_records')"))
        ->where('table', 'leave_requests')
        ->sortBy('seq')
        ->pluck('from')
        ->values()
        ->all();
    $historyColumns = collect(DB::select("PRAGMA foreign_key_list('daily_attendance_record_histories')"))
        ->where('table', 'leave_requests')
        ->sortBy('seq')
        ->pluck('from')
        ->values()
        ->all();

    expect($recordColumns)->toBe(['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'])
        ->and($historyColumns)->toBe(['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'])
        ->and(DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', [
            'ep5_c81_record_test_trigger',
            'ep5_c81_history_test_trigger',
        ])->count())->toBe(2)
        ->and(DB::table('sqlite_master')->where('type', 'index')->whereIn('name', [
            'dar_company_date_status_index',
            'dar_company_state_date_index',
            'darh_company_employee_date_created_index',
            'darh_record_created_index',
        ])->count())->toBe(4);
});
