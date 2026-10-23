<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vérification d'un numéro par WhatsApp : code prérempli dans le message que la personne envoie au numéro du support,
 * et moyen par lequel le numéro a été prouvé (whatsapp ou sms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->string('whatsapp_code', 6)->nullable()->after('token_hash')->index();
            $table->string('verified_via', 10)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('phone_verifications', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_code', 'verified_via']);
        });
    }
};
