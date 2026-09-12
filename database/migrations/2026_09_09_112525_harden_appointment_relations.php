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
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['doctor_id']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedBigInteger('doctor_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreign('patient_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('doctor_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['doctor_id']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedBigInteger('doctor_id')
                ->nullable()
                ->change();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreign('patient_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('doctor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
