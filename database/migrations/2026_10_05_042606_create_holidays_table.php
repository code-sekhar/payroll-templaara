<?php

use Illuminate\Database\Migrations\Migration;
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
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('holiday_calendar_id');
            $table->date('date');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 24);
            $table->string('status', 16)->default('active');
            $table->boolean('is_optional')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'id'], 'holidays_company_id_id_unique');
            $table->foreign(['company_id', 'holiday_calendar_id'], 'holidays_company_calendar_foreign')
                ->references(['company_id', 'id'])->on('holiday_calendars')->restrictOnDelete();
            $table->index(['company_id', 'holiday_calendar_id', 'date'], 'holidays_company_calendar_date_index');
            $table->index(['company_id', 'status', 'date'], 'holidays_company_status_date_index');
            $table->index(['company_id', 'type', 'date'], 'holidays_company_type_date_index');
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX holidays_active_date_unique ON holidays (company_id, holiday_calendar_id, date) WHERE status = 'active' AND deleted_at IS NULL");
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_valid_type CHECK (type IN ('public', 'company', 'festival', 'national', 'optional', 'other'))");
            DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_valid_status CHECK (status IN ('active', 'inactive'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS holidays_active_date_unique');
        }

        Schema::dropIfExists('holidays');
    }
};
