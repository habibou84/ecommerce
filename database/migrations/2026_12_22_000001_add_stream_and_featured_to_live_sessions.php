<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live : lien du direct (TikTok, Facebook…) pour que les clients reviennent le regarder, et article montré en ce
 * moment, mis en avant sur la page du live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('stream_url', 500)->nullable()->after('hold_minutes');
            $table->unsignedBigInteger('featured_item_id')->nullable()->after('stream_url');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn(['stream_url', 'featured_item_id']);
        });
    }
};
