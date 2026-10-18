<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relances WhatsApp des anciens clients (campagnes) et refus des clients (« STOP »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('marketing_opt_out_at')->nullable()->after('accepts_marketing');
            $table->timestamp('last_campaign_at')->nullable()->after('marketing_opt_out_at');
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('message', 300); // texte du modèle {{3}} : une ligne
            $table->string('link', 500)->nullable();
            $table->json('audience'); // {type: inactive|product|all, days, product_id, include_buyers}
            $table->string('status', 20)->default('draft'); // draft, sending, sent
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('estimated_cost')->default(0); // F CFA, coût Meta estimé
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['marketing_opt_out_at', 'last_campaign_at']);
        });
    }
};
