<?php

use App\Enums\ConsultationStatus;
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
        Schema::table('consultations', function (Blueprint $table): void {
            $table->text('symptoms');
            $table->decimal('price', 10, 2);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('replied_at')->nullable();

            $table->string('status')
                ->default(ConsultationStatus::PendingPayment->value)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->enum('status', [
                'pending_payment',
                'awaiting_reply',
                'replied',
                'closed',
            ])->default('pending_payment')->change();

            $table->dropColumn([
                'symptoms',
                'price',
                'submitted_at',
                'replied_at',
            ]);
        });
    }
};
