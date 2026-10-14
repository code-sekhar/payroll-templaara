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
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('notifiable_id')
                ->constrained()->nullOnDelete();
            $table->index(
                ['company_id', 'notifiable_type', 'notifiable_id', 'created_at', 'id'],
                'notifications_company_recipient_created_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_company_recipient_created_index');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
