<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('post_targets', 'external_post_id')) {
                $table->string('external_post_id')->nullable()->after('status');
            }
            if (!Schema::hasColumn('post_targets', 'external_url')) {
                $table->text('external_url')->nullable()->after('external_post_id');
            }
            if (!Schema::hasColumn('post_targets', 'error_message')) {
                $table->text('error_message')->nullable()->after('external_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['external_post_id', 'external_url', 'error_message']);
        });
    }
};
