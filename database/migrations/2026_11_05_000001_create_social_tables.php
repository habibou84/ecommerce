<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réseaux sociaux : comptes connectés (page Facebook, compte Instagram professionnel, compte TikTok), publications
 * de produits (programmées ou immédiates) et liens courts mesurés par réseau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20); // facebook, instagram, tiktok
            $table->string('external_id', 64); // page Facebook, compte Instagram, open_id TikTok
            $table->string('name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('page_id', 64)->nullable(); // Instagram : page Facebook liée (messages, jeton)
            $table->string('status', 20)->default('active'); // active, error
            $table->string('last_error')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'network']);
            $table->index(['network', 'external_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('caption');
            $table->json('networks'); // ["facebook", "instagram", "tiktok"]
            $table->string('square_path')->nullable();
            $table->string('vertical_path')->nullable();
            $table->json('results')->nullable(); // {facebook: {status, id, url, error}, …}
            $table->string('status', 20)->default('scheduled'); // scheduled, publishing, published, partial, failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::table('short_links', function (Blueprint $table) {
            $table->foreignId('social_post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('network', 20)->nullable();
            // Un lien de partage par produit ; les publications ont en plus un lien par réseau
            $table->dropUnique(['store_id', 'target_type', 'target_id']);
        });
        DB::statement('CREATE UNIQUE INDEX short_links_share_unique ON short_links (store_id, target_type, target_id) WHERE social_post_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS short_links_share_unique');
        Schema::table('short_links', fn (Blueprint $table) => $table->dropConstrainedForeignId('social_post_id'));
        Schema::table('short_links', fn (Blueprint $table) => $table->dropColumn('network'));
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
