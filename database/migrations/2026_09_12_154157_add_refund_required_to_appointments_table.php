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
        Schema::table('appointments', function (Blueprint $table): void {
            /*
             * Raised when a cancellation happens after the money was already
             * verified. Sprint 3 deliberately carries no refund logic, so this
             * is the queue staff work from rather than a payment state.
             */
            $table->boolean('refund_required')->default(false)->after('cancellation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('refund_required');
        });
    }
};
