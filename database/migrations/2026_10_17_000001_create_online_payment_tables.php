<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comptes du marchand chez les prestataires de paiement (l'argent va directement au marchand)
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // wave | cinetpay | paydunya
            $table->string('label')->nullable();
            $table->text('credentials'); // chiffré
            $table->string('mode', 10)->default('live'); // test | live
            $table->uuid('webhook_uuid')->unique();
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->unsignedInteger('fee_fixed')->default(0);
            $table->string('status', 20)->default('active'); // active | error | disabled
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        // Prestataire choisi pour chaque moyen de paiement, avec secours (priorité 2, 3…)
        Schema::create('payment_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // wave | orange_money | mtn_momo | moov_money | card
            $table->foreignId('payment_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);

            $table->unique(['store_id', 'method', 'payment_account_id']);
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 32)->unique();
            $table->unsignedInteger('amount');
            $table->string('description')->nullable();
            $table->string('status', 20)->default('open'); // open | paid | expired | cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('payment_account_id')->references('id')->on('payment_accounts')->nullOnDelete();
            $table->foreignId('payment_link_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->string('checkout_url', 1000)->nullable()->after('provider_reference');
            $table->json('raw')->nullable()->after('checkout_url');
            $table->string('failure_reason')->nullable()->after('raw');
            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('method', 20); // provider | manual
            $table->string('reason')->nullable();
            $table->string('status', 20); // succeeded | failed
            $table->string('provider_reference')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_account_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();

            $table->unique(['payment_account_id', 'event_id']);
        });

        // Paiement en ligne proposé sur la boutique : off | optional (au choix du client) | deposit (acompte exigé) | required
        Schema::table('stores', function (Blueprint $table) {
            $table->string('online_payment', 20)->default('off')->after('storefront_enabled');
            $table->unsignedTinyInteger('deposit_percent')->nullable()->after('online_payment');
        });

        // WhatsApp Cloud API au nom du marchand
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_number_id');
            $table->string('waba_id')->nullable();
            $table->string('display_phone', 20)->nullable();
            $table->text('access_token');   // chiffré
            $table->text('app_secret');     // chiffré : signature des webhooks de Meta
            $table->string('verify_token', 64);
            $table->uuid('webhook_uuid')->unique();
            $table->json('templates')->nullable(); // {événement: nom du modèle approuvé chez Meta}
            $table->json('automations')->nullable(); // {événement: true|false}
            $table->string('status', 20)->default('active');
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp | whatsapp_link
            $table->string('to_phone', 20);
            $table->string('event', 40);
            $table->text('body');
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 20); // queued | sent | delivered | read | failed
            $table->string('provider_message_id')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('whatsapp_accounts');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['online_payment', 'deposit_percent']));
        Schema::dropIfExists('payment_webhook_receipts');
        Schema::dropIfExists('refunds');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_account_id']);
            $table->dropIndex(['provider', 'provider_reference']);
            $table->dropConstrainedForeignId('payment_link_id');
            $table->dropColumn(['checkout_url', 'raw', 'failure_reason']);
        });
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('payment_routes');
        Schema::dropIfExists('payment_accounts');
    }
};
