<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse de la boutique : ouverture (fond de caisse), mouvements hors ventes (dépenses, retraits, apports) et
 * clôture (comptage, écart). Une vente au comptoir peut se faire sans numéro de téléphone du client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('opening_float');
            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('expected_cash')->nullable();
            $table->unsignedInteger('counted_cash')->nullable();
            $table->integer('difference')->nullable(); // compté − attendu
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'closed_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // expense | withdrawal | deposit
            $table->string('category', 30)->nullable(); // dépenses : stock, transport, packaging, salary, rent, utilities, marketing, other
            $table->unsignedInteger('amount');
            $table->string('method', 20)->default('cash');
            $table->string('note')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'occurred_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
