<?php

use App\Enums\AppointmentStatus;
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
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('hold_expires_at')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->decimal('price', 10, 2);

            $table->string('status')
                ->default(AppointmentStatus::PendingPayment->value)
                ->change();

            $table->index(['doctor_id', 'start_at']);
            $table->index('status');

            $table->dropColumn(['requested_date', 'duration_minutes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['doctor_id', 'start_at']);
            $table->dropIndex(['status']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'cancelled',
            ])->default('pending')->change();

            $table->date('requested_date');
            $table->integer('duration_minutes')->nullable();

            $table->dropColumn([
                'start_at',
                'end_at',
                'hold_expires_at',
                'approved_by',
                'approved_at',
                'rejection_reason',
                'cancelled_at',
                'cancellation_reason',
                'price',
            ]);
        });
    }
};
