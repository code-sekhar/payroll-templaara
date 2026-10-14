<?php

use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') !== '1') {
        $this->markTestSkipped('Set EP4_POSTGRES_TESTING=1 to run destructive PostgreSQL tests against the isolated EP4 test database.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'payroll_ep4_hardening_test') {
        throw new LogicException('PostgreSQL Attendance Leave ownership tests may only use the payroll_ep4_hardening_test database.');
    }

    DB::unprepared('DROP SCHEMA public CASCADE');
    DB::unprepared('CREATE SCHEMA public');
    $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
});

afterEach(function (): void {
    if (getenv('EP4_POSTGRES_TESTING') === '1'
        && config('database.connections.pgsql.database') === 'payroll_ep4_hardening_test') {
        DB::unprepared('DROP SCHEMA public CASCADE');
        DB::unprepared('CREATE SCHEMA public');
    }
});

it('installs the corrected ordered foreign keys with the original actions on both Attendance tables', function (): void {
    foreach ([
        ['daily_attendance_records', 'dar_leave_request_ownership_foreign'],
        ['daily_attendance_record_histories', 'darh_leave_request_ownership_foreign'],
    ] as [$tableName, $constraintName]) {
        $definition = collect(DB::select(<<<'SQL'
            SELECT
                local_columns.ordinality,
                local_attribute.attname AS child_column,
                referenced_relation.relname AS referenced_table,
                referenced_attribute.attname AS referenced_column,
                constraint_row.confupdtype AS on_update,
                constraint_row.confdeltype AS on_delete
            FROM pg_constraint AS constraint_row
            CROSS JOIN LATERAL unnest(constraint_row.conkey) WITH ORDINALITY AS local_columns(attribute_number, ordinality)
            JOIN pg_attribute AS local_attribute
                ON local_attribute.attrelid = constraint_row.conrelid
                AND local_attribute.attnum = local_columns.attribute_number
            CROSS JOIN LATERAL unnest(constraint_row.confkey) WITH ORDINALITY AS referenced_columns(attribute_number, ordinality)
            JOIN pg_attribute AS referenced_attribute
                ON referenced_attribute.attrelid = constraint_row.confrelid
                AND referenced_attribute.attnum = referenced_columns.attribute_number
                AND referenced_columns.ordinality = local_columns.ordinality
            JOIN pg_class AS referenced_relation ON referenced_relation.oid = constraint_row.confrelid
            WHERE constraint_row.contype = 'f'
                AND constraint_row.conrelid = to_regclass(?)
                AND constraint_row.conname = ?
            ORDER BY local_columns.ordinality
            SQL, [$tableName, $constraintName]));

        expect($definition->pluck('child_column')->all())->toBe([
            'company_id', 'leave_request_id', 'employee_id', 'leave_type_id',
        ])
            ->and($definition->pluck('referenced_table')->unique()->values()->all())->toBe(['leave_requests'])
            ->and($definition->pluck('referenced_column')->all())->toBe([
                'company_id', 'id', 'employee_id', 'leave_type_id',
            ])
            ->and($definition->pluck('on_delete')->unique()->values()->all())->toBe(['r'])
            ->and($definition->pluck('on_update')->unique()->values()->all())->toBe(['a']);
    }

    expect(DB::table('pg_constraint')->whereIn('conname', [
        'dar_leave_day_ownership_foreign',
        'dar_leave_type_ownership_foreign',
        'darh_leave_day_ownership_foreign',
        'darh_leave_type_ownership_foreign',
    ])->count())->toBe(4);
});

it('accepts valid Leave references and rejects invalid request, Company, Employee, and Leave Type ownership', function (): void {
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    $leaveRequest = LeaveRequest::factory()->create(['company_id' => $company->getKey()]);
    $employee = Employee::query()->findOrFail($leaveRequest->employee_id);
    $leaveDay = LeaveRequestDay::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_request_id' => $leaveRequest->getKey(),
    ]);
    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $employee->getKey(),
        'leave_request_id' => $leaveRequest->getKey(),
        'leave_request_day_id' => $leaveDay->getKey(),
        'leave_type_id' => $leaveRequest->leave_type_id,
    ]);
    $otherRequest = LeaveRequest::factory()->create(['company_id' => $company->getKey()]);
    $foreignRequest = LeaveRequest::factory()->create(['company_id' => $foreignCompany->getKey()]);
    $differentLeaveType = LeaveType::factory()->create(['company_id' => $company->getKey()]);

    $invalidRecordReferences = [
        ['leave_request_id' => 900001, 'leave_type_id' => $leaveRequest->leave_type_id],
        ['leave_request_id' => $otherRequest->getKey(), 'leave_type_id' => $otherRequest->leave_type_id],
        ['leave_request_id' => $leaveRequest->getKey(), 'leave_type_id' => $differentLeaveType->getKey()],
        ['leave_request_id' => $foreignRequest->getKey(), 'leave_type_id' => $leaveRequest->leave_type_id],
    ];

    foreach ($invalidRecordReferences as $index => $invalidReference) {
        $attributes = $record->getAttributes();
        unset($attributes['id']);
        $attributes['attendance_date'] = '2026-11-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        $attributes['leave_request_day_id'] = null;
        $attributes['leave_request_id'] = $invalidReference['leave_request_id'];
        $attributes['leave_type_id'] = $invalidReference['leave_type_id'];

        expect(fn () => DB::table('daily_attendance_records')->insert($attributes))
            ->toThrow(QueryException::class);
    }

    expect($record->fresh()->leave_request_id)->toBe($leaveRequest->getKey())
        ->and(fn () => DB::table('daily_attendance_records')->where('id', $record->getKey())->update([
            'leave_request_day_id' => 900002,
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('daily_attendance_records')->where('id', $record->getKey())->update([
            'leave_type_id' => $differentLeaveType->getKey(),
        ]))->toThrow(QueryException::class);

    $history = [
        'company_id' => $company->getKey(),
        'daily_attendance_record_id' => $record->getKey(),
        'employee_id' => $employee->getKey(),
        'attendance_date' => $record->attendance_date->toDateString(),
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
        'leave_request_day_id' => $leaveDay->getKey(),
        'leave_type_id' => $leaveRequest->leave_type_id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ];
    DB::table('daily_attendance_record_histories')->insert($history);

    expect(DB::table('daily_attendance_record_histories')->where('daily_attendance_record_id', $record->getKey())->value('leave_request_id'))
        ->toBe($leaveRequest->getKey());

    $invalidHistoryType = $history;
    $invalidHistoryType['leave_request_day_id'] = null;
    $invalidHistoryType['leave_type_id'] = $differentLeaveType->getKey();
    expect(fn () => DB::table('daily_attendance_record_histories')->insert($invalidHistoryType))
        ->toThrow(QueryException::class);

    $nullHistoryReferences = $history;
    $nullHistoryReferences['leave_request_id'] = null;
    $nullHistoryReferences['leave_request_day_id'] = null;
    $nullHistoryReferences['leave_type_id'] = null;
    $nullHistoryReferences['created_at'] = now('UTC')->addSecond();
    DB::table('daily_attendance_record_histories')->insert($nullHistoryReferences);

    $history['leave_request_id'] = 900003;
    $history['leave_request_day_id'] = null;
    expect(fn () => DB::table('daily_attendance_record_histories')->insert($history))
        ->toThrow(QueryException::class);
});

it('rejects invalid existing ownership rows before changing either constraint', function (): void {
    $company = Company::factory()->create();
    $firstEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $secondEmployee = Employee::factory()->create(['company_id' => $company->getKey()]);
    $assignment = EmployeeLeavePolicyAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $secondEmployee->getKey(),
    ]);
    $leaveRequest = LeaveRequest::factory()->create([
        'id' => $firstEmployee->getKey(),
        'company_id' => $company->getKey(),
        'employee_id' => $secondEmployee->getKey(),
        'leave_type_id' => $assignment->leave_type_id,
        'leave_policy_id' => $assignment->leave_policy_id,
        'employee_leave_policy_assignment_id' => $assignment->getKey(),
    ]);

    foreach ([
        ['daily_attendance_records', 'dar_leave_request_ownership_foreign'],
        ['daily_attendance_record_histories', 'darh_leave_request_ownership_foreign'],
    ] as [$tableName, $constraintName]) {
        DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$constraintName}");
        DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} FOREIGN KEY (company_id, employee_id, leave_request_id, leave_type_id) REFERENCES leave_requests (company_id, id, employee_id, leave_type_id) ON DELETE RESTRICT ON UPDATE NO ACTION");
    }

    $record = DailyAttendanceRecord::factory()->create([
        'company_id' => $company->getKey(),
        'employee_id' => $firstEmployee->getKey(),
        'leave_request_id' => $secondEmployee->getKey(),
        'leave_type_id' => $leaveRequest->leave_type_id,
    ]);

    DB::table('daily_attendance_record_histories')->insert([
        'company_id' => $company->getKey(),
        'daily_attendance_record_id' => $record->getKey(),
        'employee_id' => $firstEmployee->getKey(),
        'attendance_date' => $record->attendance_date->toDateString(),
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
        'leave_request_id' => $secondEmployee->getKey(),
        'leave_request_day_id' => null,
        'leave_type_id' => $leaveRequest->leave_type_id,
        'leave_amount' => '1.00',
        'leave_is_paid' => true,
    ]);

    $migration = require database_path('migrations/2026_10_09_080621_repair_attendance_leave_request_ownership_foreign_keys.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, '1 invalid rows in daily_attendance_records and 1 invalid rows in daily_attendance_record_histories')
        ->and(DB::table('daily_attendance_records')->where('id', $record->getKey())->count())->toBe(1)
        ->and(DB::table('daily_attendance_record_histories')->where('daily_attendance_record_id', $record->getKey())->count())->toBe(1)
        ->and(DB::table('pg_constraint')->whereIn('conname', [
            'dar_leave_request_ownership_foreign',
            'darh_leave_request_ownership_foreign',
        ])->count())->toBe(2);
});

it('preserves existing row counts while replacing the wrong key ordering', function (): void {
    $record = DailyAttendanceRecord::factory()->create();
    DB::table('daily_attendance_record_histories')->insert([
        'company_id' => $record->company_id,
        'daily_attendance_record_id' => $record->getKey(),
        'employee_id' => $record->employee_id,
        'attendance_date' => $record->attendance_date->toDateString(),
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
    ]);

    foreach ([
        ['daily_attendance_records', 'dar_leave_request_ownership_foreign'],
        ['daily_attendance_record_histories', 'darh_leave_request_ownership_foreign'],
    ] as [$tableName, $constraintName]) {
        DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$constraintName}");
        DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} FOREIGN KEY (company_id, employee_id, leave_request_id, leave_type_id) REFERENCES leave_requests (company_id, id, employee_id, leave_type_id) ON DELETE RESTRICT ON UPDATE NO ACTION");
    }

    $migration = require database_path('migrations/2026_10_09_080621_repair_attendance_leave_request_ownership_foreign_keys.php');
    $migration->up();

    expect(DB::table('daily_attendance_records')->where('id', $record->getKey())->count())->toBe(1)
        ->and(DB::table('daily_attendance_record_histories')->where('daily_attendance_record_id', $record->getKey())->count())->toBe(1)
        ->and(DB::table('daily_attendance_records')->where('id', $record->getKey())->value('leave_request_id'))->toBeNull()
        ->and(DB::table('pg_indexes')->whereIn('indexname', [
            'dar_company_employee_date_unique',
            'dar_company_id_id_unique',
            'dar_company_date_status_index',
            'dar_company_state_date_index',
            'darh_company_employee_date_created_index',
            'darh_record_created_index',
        ])->count())->toBe(6);
});
