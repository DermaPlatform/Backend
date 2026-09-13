<?php

use App\Enums\PaymentStatus;
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
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('receipt_number')->nullable()->unique();
            $table->string('receipt_path')->nullable();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('paid_at')->nullable();

            $table->string('status')
                ->default(PaymentStatus::Pending->value)
                ->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('patient_id')
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
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('patient_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropUnique(['receipt_number']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'paid',
                'failed',
            ])->default('pending')->change();

            $table->dropColumn([
                'receipt_number',
                'receipt_path',
                'method',
                'reference',
                'verified_by',
                'verified_at',
                'rejection_reason',
                'paid_at',
            ]);
        });
    }
};
