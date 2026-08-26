<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->string('platform')->index(); // linkedin, meta, instagram, x, tiktok, youtube
            $table->string('platform_account_id')->index();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('account_type')->default('profile'); // profile, page, group, channel
            $table->text('avatar_url')->nullable();
            $table->string('connection_status')->default('connected')->index(); // connected, expired, disconnected
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'platform_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
