<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Attendance Leave ownership repair is not supported for the [{$driver}] database driver.");
        }

        if ($driver === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement('LOCK TABLE daily_attendance_records, daily_attendance_record_histories IN ACCESS EXCLUSIVE MODE');
                $this->repairConstraints('pgsql');
            });

            return;
        }

        $this->repairConstraints('sqlite');
    }

    private function repairConstraints(string $driver): void
    {
        $constraintsToRepair = $this->constraintsToRepair($driver);

        $invalidRecordCount = $this->invalidLeaveReferenceCount('daily_attendance_records');
        $invalidHistoryCount = $this->invalidLeaveReferenceCount('daily_attendance_record_histories');

        if ($invalidRecordCount > 0 || $invalidHistoryCount > 0) {
            throw new RuntimeException(
                "Cannot repair Attendance Leave ownership constraints: {$invalidRecordCount} invalid rows in daily_attendance_records and {$invalidHistoryCount} invalid rows in daily_attendance_record_histories. Investigate the existing references before retrying."
            );
        }

        foreach ($constraintsToRepair as [$tableName, $constraintName, $oldColumns]) {
            $this->replaceConstraint($driver, $tableName, $constraintName, $oldColumns);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('This Attendance Leave ownership repair is intentionally irreversible; restoring the prior constraint would reintroduce the integrity defect.');
    }

    /**
     * @param  list<string>  $oldColumns
     */
    private function replaceConstraint(string $driver, string $tableName, string $constraintName, array $oldColumns): void
    {
        if ($driver === 'sqlite') {
            $this->replaceSqliteConstraint($tableName, $oldColumns);

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
            $table->dropForeign($constraintName);
            $table->foreign(['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'], $constraintName)
                ->references(['company_id', 'id', 'employee_id', 'leave_type_id'])
                ->on('leave_requests')
                ->noActionOnUpdate()
                ->restrictOnDelete();
        });
    }

    /**
     * @param  list<string>  $oldColumns
     */
    private function replaceSqliteConstraint(string $tableName, array $oldColumns): void
    {
        $triggerDefinitions = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', $tableName)
            ->orderBy('name')
            ->get(['name', 'sql'])
            ->filter(fn (object $trigger): bool => is_string($trigger->sql))
            ->values();

        try {
            Schema::table($tableName, function (Blueprint $table) use ($oldColumns): void {
                $table->dropForeign($oldColumns);
                $table->foreign(['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'])
                    ->references(['company_id', 'id', 'employee_id', 'leave_type_id'])
                    ->on('leave_requests')
                    ->noActionOnUpdate()
                    ->restrictOnDelete();
            });
        } finally {
            foreach ($triggerDefinitions as $triggerDefinition) {
                $exists = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('name', $triggerDefinition->name)
                    ->exists();

                if (! $exists) {
                    DB::unprepared($triggerDefinition->sql);
                }
            }
        }
    }

    private function constraintsToRepair(string $driver): array
    {
        $constraintsToRepair = [];

        foreach ([
            ['daily_attendance_records', 'dar_leave_request_ownership_foreign'],
            ['daily_attendance_record_histories', 'darh_leave_request_ownership_foreign'],
        ] as [$tableName, $constraintName]) {
            $originalChildColumns = ['company_id', 'employee_id', 'leave_request_id', 'leave_type_id'];
            $correctedChildColumns = ['company_id', 'leave_request_id', 'employee_id', 'leave_type_id'];
            $expectedReferencedColumns = ['company_id', 'id', 'employee_id', 'leave_type_id'];

            $definition = $driver === 'pgsql'
                ? $this->postgresConstraintDefinition($tableName, $constraintName)
                : $this->sqliteConstraintDefinition($tableName);

            if ($definition['referenced_table'] !== 'leave_requests'
                || $definition['referenced_columns'] !== $expectedReferencedColumns
                || $definition['on_update'] !== 'NO ACTION'
                || $definition['on_delete'] !== 'RESTRICT') {
                throw new RuntimeException("Cannot repair {$constraintName}: the deployed constraint definition differs from the expected original definition.");
            }

            if ($definition['child_columns'] === $originalChildColumns) {
                $constraintsToRepair[] = [
                    $tableName,
                    $constraintName,
                    $originalChildColumns,
                ];

                continue;
            }

            if ($definition['child_columns'] !== $correctedChildColumns) {
                throw new RuntimeException("Cannot repair {$constraintName}: the deployed constraint definition differs from both the defective and corrected definitions.");
            }
        }

        return $constraintsToRepair;
    }

    /**
     * @return array{child_columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_update: string, on_delete: string}
     */
    private function postgresConstraintDefinition(string $tableName, string $constraintName): array
    {
        $rows = collect(DB::select(<<<'SQL'
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

        if ($rows->isEmpty()) {
            throw new RuntimeException("Cannot repair {$constraintName}: the expected deployed foreign key was not found.");
        }

        return [
            'child_columns' => $rows->pluck('child_column')->all(),
            'referenced_table' => (string) $rows->first()->referenced_table,
            'referenced_columns' => $rows->pluck('referenced_column')->all(),
            'on_update' => $this->postgresActionName((string) $rows->first()->on_update),
            'on_delete' => $this->postgresActionName((string) $rows->first()->on_delete),
        ];
    }

    private function postgresActionName(string $action): string
    {
        return match ($action) {
            'a' => 'NO ACTION',
            'r' => 'RESTRICT',
            'c' => 'CASCADE',
            'n' => 'SET NULL',
            'd' => 'SET DEFAULT',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return array{child_columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_update: string, on_delete: string}
     */
    private function sqliteConstraintDefinition(string $tableName): array
    {
        $groups = collect(DB::select("PRAGMA foreign_key_list('{$tableName}')"))
            ->groupBy('id');

        foreach ($groups as $rows) {
            $orderedRows = $rows->sortBy('seq')->values();

            if ($orderedRows->first()->table === 'leave_requests'
                && $orderedRows->pluck('to')->all() === ['company_id', 'id', 'employee_id', 'leave_type_id']) {
                return [
                    'child_columns' => $orderedRows->pluck('from')->all(),
                    'referenced_table' => (string) $orderedRows->first()->table,
                    'referenced_columns' => $orderedRows->pluck('to')->all(),
                    'on_update' => strtoupper((string) $orderedRows->first()->on_update),
                    'on_delete' => strtoupper((string) $orderedRows->first()->on_delete),
                ];
            }
        }

        throw new RuntimeException("Cannot repair the Leave Request ownership constraint on {$tableName}: the expected deployed foreign key was not found.");
    }

    private function invalidLeaveReferenceCount(string $tableName): int
    {
        return DB::table("{$tableName} as attendance")
            ->whereNotNull('attendance.leave_request_id')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('leave_requests as requests')
                    ->whereColumn('requests.company_id', 'attendance.company_id')
                    ->whereColumn('requests.id', 'attendance.leave_request_id')
                    ->whereColumn('requests.employee_id', 'attendance.employee_id')
                    ->whereColumn('requests.leave_type_id', 'attendance.leave_type_id');
            })
            ->count();
    }
};
