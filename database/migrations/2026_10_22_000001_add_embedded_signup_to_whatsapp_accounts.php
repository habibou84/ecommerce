<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp connecté en un clic (inscription intégrée de Meta) : le numéro passe par l'application Meta de la
 * plateforme (pas de clé secrète propre à la boutique), avec son code PIN de vérification en deux étapes et l'état
 * des modèles de messages créés automatiquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('connection', 20)->default('manual')->after('store_id'); // manual | embedded
            $table->text('app_secret')->nullable()->change();
            $table->text('two_step_pin')->nullable(); // chiffré
            $table->json('template_status')->nullable(); // {événement: PENDING | APPROVED | REJECTED…}
            $table->index('waba_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropIndex(['waba_id']);
            $table->dropColumn(['connection', 'two_step_pin', 'template_status']);
        });
    }
};
