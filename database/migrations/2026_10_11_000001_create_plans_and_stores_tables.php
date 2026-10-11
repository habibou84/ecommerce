<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedInteger('monthly_price')->default(0);
            $table->unsignedInteger('yearly_price')->nullable();
            $table->json('limits');   // null = illimité
            $table->json('features');
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Une boutique = un sous-domaine {slug}.jibiat-marchand.com (site public et gestion)
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('tagline', 160)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->char('country', 2)->default('CI');
            $table->char('currency', 3)->default('XOF');
            $table->string('timezone')->default('Africa/Abidjan');
            $table->string('locale', 5)->default('fr');
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('order_prefix', 10)->default('#');
            $table->unsignedInteger('next_order_number')->default(1001);
            $table->boolean('require_order_confirmation')->default(true);
            $table->boolean('require_delivery_code')->default(false);
            $table->string('default_fee_payer', 20)->default('customer');
            $table->boolean('allow_backorder')->default(false);
            $table->unsignedInteger('low_stock_threshold')->default(3);
            $table->string('status', 20)->default('trial');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('store_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20);
            $table->string('role', 30);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'phone']);
        });

        // Domaine personnalisé : sert uniquement la boutique publique (phase 8)
        Schema::create('store_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 20)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        // Ancien sous-domaine redirigé (301) pendant 90 jours après un changement d'adresse
        Schema::create('slug_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('old_slug', 60)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 20);
            $table->string('billing_cycle', 10)->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->index(['status', 'current_period_end']);
        });

        // Vérification d'un numéro par code SMS : création de boutique, invitation, mot de passe oublié
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 20);
            $table->string('phone', 20);
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('sms_code_hash')->nullable();
            $table->unsignedSmallInteger('sms_count')->default(0);
            $table->timestamp('sms_sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('payload')->nullable(); // chiffré
            $table->string('ip', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['phone', 'purpose']);
            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('slug_history');
        Schema::dropIfExists('store_domains');
        Schema::dropIfExists('store_invitations');
        Schema::dropIfExists('store_users');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('plans');
    }
};
