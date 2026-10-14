<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table): void {
            $table->unique(['company_id', 'employee_id', 'id'], 'ap_company_employee_id_unique');
        });

        Schema::create('attendance_regularization_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('daily_attendance_record_id')->nullable();
            $table->date('attendance_date');
            $table->string('request_type', 32);
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->text('employee_note')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at', precision: 6);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at', precision: 6)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'arr_company_id_id_unique');
            $table->unique(['company_id', 'employee_id', 'id'], 'arr_company_employee_id_unique');
            $table->foreign(['company_id', 'employee_id'], 'arr_company_employee_foreign')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'daily_attendance_record_id'], 'arr_company_daily_record_foreign')
                ->references(['company_id', 'id'])->on('daily_attendance_records')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'attendance_date'], 'arr_company_employee_date_index');
            $table->index(['company_id', 'status', 'attendance_date'], 'arr_company_status_date_index');
            $table->index(['company_id', 'requested_at'], 'arr_company_requested_at_index');
        });

        DB::statement("CREATE UNIQUE INDEX arr_pending_employee_date_unique ON attendance_regularization_requests (company_id, employee_id, attendance_date) WHERE status = 'pending'");

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_regularization_requests ADD CONSTRAINT arr_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
            DB::statement("ALTER TABLE attendance_regularization_requests ADD CONSTRAINT arr_type_check CHECK (request_type IN ('missing_check_in', 'missing_check_out', 'missing_punch_pair', 'incorrect_punch', 'duplicate_punch', 'other'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_regularization_requests');

        Schema::table('attendance_punches', function (Blueprint $table): void {
            $table->dropUnique('ap_company_employee_id_unique');
        });
    }
};
