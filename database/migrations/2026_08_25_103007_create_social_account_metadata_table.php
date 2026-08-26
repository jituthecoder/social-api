<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_account_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->string('meta_key')->index();
            $table->text('meta_value')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'meta_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_metadata');
    }
};
