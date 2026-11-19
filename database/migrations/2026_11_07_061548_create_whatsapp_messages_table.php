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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->nullable()->index();
            $table->text('message')->nullable();
            $table->string('from')->nullable()->index();
            $table->string('sender_name')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('display_phone_number')->nullable();
            $table->string('type')->nullable()->index();
            $table->text('caption')->nullable();
            $table->string('media_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->dateTime('datetime')->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('timestamp')->nullable(); // unix timestamp, number of seconds elapsed since January 1, 1970, 00:00:00 UTC.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
