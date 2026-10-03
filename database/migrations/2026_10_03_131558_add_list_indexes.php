<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the filters and orderings the list endpoints actually run
     * (US-023). Each one is named after the query it serves.
     */
    public function up(): void
    {
        // A patient's own appointments, and a clinic's patient list with each one's last visit.
        Schema::table('appointments', function (Blueprint $table): void {
            $table->index(['patient_id', 'start_at']);
        });

        // The receipt review queue: one status, newest first.
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'created_at']);
        });

        // A doctor's answered consultations inside a period, for the income report.
        Schema::table('consultations', function (Blueprint $table): void {
            $table->index(['doctor_id', 'replied_at']);
        });

        // The audit trail is read newest first, optionally one action at a time.
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index(['action', 'created_at']);
        });

        // The subscriptions list orders and filters on the end date, and so does the catalog.
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->index(['subscription_status', 'subscription_ends_at']);
        });

        // Every staff and patient list starts from one role and orders by name.
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['role', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'name']);
        });

        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropIndex(['subscription_status', 'subscription_ends_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['action', 'created_at']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropIndex(['doctor_id', 'replied_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['patient_id', 'start_at']);
        });
    }
};
