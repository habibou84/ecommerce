<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matériel vendu aux e-commerçants par la plateforme (étiquettes, imprimantes, accessoires de live…) :
 * produits (stock de la plateforme ou fournisseur partenaire à commission) et commandes des espaces marchands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 20)->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('price');
            // Prix d'achat (stock de la plateforme) : calcul de la marge
            $table->unsignedInteger('cost_price')->nullable();
            // Stock de la plateforme ; vide : pas suivi (ou chez le partenaire)
            $table->integer('stock')->nullable();
            $table->string('supplier', 10)->default('own');
            $table->string('partner_name', 120)->nullable();
            $table->string('partner_phone', 30)->nullable();
            // Commission du partenaire : pourcentage du prix ou montant par pièce
            $table->string('commission_type', 10)->nullable();
            $table->unsignedInteger('commission_value')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('accessory_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('pending')->index();
            // Lignes figées à la commande : nom, prix, quantité, fournisseur, gain de la plateforme
            $table->json('items');
            $table->unsignedInteger('subtotal');
            $table->json('delivery');
            $table->unsignedInteger('delivery_fee')->default(0);
            $table->unsignedInteger('total');
            // Ce que la vente rapporte : marge (stock de la plateforme) et commissions (partenaires) ; vide si inconnu
            $table->integer('earnings')->nullable();
            $table->string('payment_method', 10);
            $table->string('payment_status', 10)->default('unpaid');
            $table->string('payer_phone', 30)->nullable();
            $table->string('contact_name', 120);
            $table->string('contact_phone', 30);
            $table->string('address', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_orders');
        Schema::dropIfExists('accessories');
    }
};
