<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_account_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->text('access_token'); // Encrypted at rest via Eloquent 'encrypted' cast
            $table->text('refresh_token')->nullable(); // Encrypted at rest via Eloquent 'encrypted' cast
            $table->timestamp('expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_tokens');
    }
};
