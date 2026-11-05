<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comment le numéro WhatsApp apparaît sur les écrans filmés du live (télé, TikTok LIVE Studio) : « dans la bio »
 * (pas de numéro à l'écran), chiffres espacés, chiffres en émojis ou coupé en deux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('live_phone_style', 10)->default('bio');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('live_phone_style');
        });
    }
};
