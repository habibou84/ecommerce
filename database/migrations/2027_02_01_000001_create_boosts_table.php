<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pubs Facebook et Instagram créées par l'assistant Boost (payées à Meta par la carte du marchand)
        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // product | products | catalog
            $table->json('product_ids')->nullable();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete(); // catalogue : une collection, ou tout
            $table->string('name');
            $table->unsignedInteger('daily_budget'); // FCFA
            $table->unsignedSmallInteger('days');
            $table->json('zones')->nullable(); // clés des lieux ; vide = tout le pays
            $table->unsignedTinyInteger('age_min')->default(18);
            $table->unsignedTinyInteger('age_max')->default(65);
            $table->string('gender', 6)->default('all'); // all | women | men
            $table->text('message');
            $table->string('status', 12)->default('review'); // review | active | paused | refused | ended | failed
            $table->string('ad_account_id', 40);
            $table->char('currency', 3)->default('XOF'); // devise du compte publicitaire
            $table->string('page_id', 40);
            $table->string('campaign_id', 40)->nullable();
            $table->string('adset_id', 40)->nullable();
            $table->string('ad_id', 40)->nullable();
            $table->string('product_set_id', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('spend')->default(0); // FCFA
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('sales')->default(0); // FCFA
            $table->unsignedInteger('billed_orders')->default(0);
            $table->unsignedInteger('fee')->default(0); // FCFA retirés du crédit
            $table->timestamp('ends_at');
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boosts');
    }
};
