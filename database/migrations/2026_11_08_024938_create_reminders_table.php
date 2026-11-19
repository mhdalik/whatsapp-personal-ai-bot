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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->text('message')->nullable();
            $table->string('cron_rule')->nullable(); // standard 5-part cron, e.g. "0 18 * * 6" or null for one-off
            $table->string('function')->default('sendMessage')->index(); // name of function to call when dispatched
            $table->dateTime('next_run_at')->nullable()->index();
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->string('status')->default('active')->index(); // status: active, inactive, finished
            $table->string('original_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
