<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index(); // e.g. stripe, linkedin, meta, x
            $table->string('event_type')->index();
            $table->string('external_event_id')->nullable()->index();
            $table->json('payload');
            $table->string('processed_status')->default('pending')->index(); // pending, processed, failed, ignored
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
