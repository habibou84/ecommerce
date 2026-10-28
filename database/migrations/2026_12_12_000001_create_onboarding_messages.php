<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages WhatsApp d'accueil des nouveaux commerçants, envoyés par le numéro du support : une ligne par boutique et
 * par étape (envoyé, sauté ou échoué), et l'arrêt demandé par le commerçant (« STOP »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step', 30); // welcome, no_product, first_products, trial_end
            $table->string('status', 12); // sent, skipped, failed
            $table->string('detail', 255)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'step']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('platform_messages_stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_messages_stopped_at');
        });
        Schema::dropIfExists('onboarding_messages');
    }
};
