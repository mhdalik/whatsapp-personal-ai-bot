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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->dateTime('datetime')->index();
            $table->string('description');
            $table->decimal('income', 10, 2)->nullable();
            $table->decimal('expense', 10, 2)->nullable();
            $table->boolean('is_loan')->default(false)->index();
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
        Schema::dropIfExists('transactions');
    }
};
