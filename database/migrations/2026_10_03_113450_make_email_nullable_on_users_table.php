<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient booked over the phone by the secretary has a name and a
     * number and often nothing else. The unique index stays: it allows any
     * number of nulls while still refusing a duplicate address.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
