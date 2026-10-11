<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Factures d'abonnement, payées sur le compte de la plateforme (docs/PLAN.md §6.5)
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('number', 20)->unique(); // JM-2026-000123
            $table->string('billing_cycle', 10); // monthly | yearly
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('pending'); // pending | paid | failed | cancelled | offered
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('method', 20)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('provider_reference');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('suspended_reason')->nullable()->after('status');
            // Avertissements déjà envoyés (renouvellement, quota de commandes) : une fois par période
            $table->json('billing_notices')->nullable()->after('suspended_reason');
        });

        // Sessions de support : un administrateur de la plateforme entre dans la gestion d'une boutique,
        // pour une durée limitée, avec un motif ; la boutique est prévenue et peut y mettre fin.
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();

            $table->index(['store_id', 'ended_at']);
            $table->index(['admin_id', 'ended_at']);
        });

        // Journal des actions des administrateurs de la plateforme
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['store_id', 'created_at']);
        });

        Schema::table('store_domains', function (Blueprint $table) {
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_domains', fn (Blueprint $table) => $table->dropIndex(['verified_at']));
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('support_sessions');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['suspended_reason', 'billing_notices']));
        Schema::dropIfExists('subscription_invoices');
    }
};
