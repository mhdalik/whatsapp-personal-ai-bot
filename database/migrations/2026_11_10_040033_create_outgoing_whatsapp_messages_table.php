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
        Schema::create('outgoing_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('to');
            $table->text('message')->nullable();
            $table->string('type')->default('text')->nullable(); //text, interactive, ....
            $table->dateTime('sent_at')->nullable(); // sent time from laravel
            $table->json('meta')->nullable();
            $table->string('timestamp')->nullable(); // unix timestamp, number of seconds elapsed since January 1, 1970, 00:00:00 UTC.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outgoing_whatsapp_messages');
    }
};
