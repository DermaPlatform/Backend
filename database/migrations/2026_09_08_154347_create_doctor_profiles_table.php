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
        Schema::create('doctor_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->primary('user_id');

            $table->string('specialty')->nullable();
            $table->boolean('accepts_appointments')->default(true);
            $table->boolean('accepts_online_consultations')->default(false);
            $table->string('subscription_status')->default('trial');
            $table->dateTime('subscription_ends_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('specialty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('specialty')->nullable()->after('role');
        });

        Schema::dropIfExists('doctor_profiles');
    }
};
