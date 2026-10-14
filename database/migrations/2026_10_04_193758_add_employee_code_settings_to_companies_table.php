<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('employee_code_prefix', 16)->default('EMP');
            $table->unsignedBigInteger('employee_code_next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['employee_code_prefix', 'employee_code_next_number']);
        });
    }
};
