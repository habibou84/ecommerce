<?php

use App\Services\Help\HelpCenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre d'aide : fiches écrites dans la console (réponse courte, étapes, vidéo, images, écran à ouvrir), avis des
 * marchands (utile ou non) et recherches restées sans réponse. Les fiches de départ sont créées ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('category', 40)->index();
            $table->string('title', 160);
            $table->string('summary', 600);
            $table->json('steps')->nullable();
            $table->text('body')->nullable();
            $table->string('keywords', 500)->nullable();
            $table->string('video_url')->nullable();
            $table->string('video_path')->nullable();
            $table->string('poster_url')->nullable();
            $table->unsignedSmallInteger('video_seconds')->nullable();
            $table->json('images')->nullable();
            $table->string('screen', 160)->nullable();
            $table->string('screen_label', 60)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });
        Schema::create('help_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('helpful');
            $table->timestamps();
            $table->unique(['help_article_id', 'user_id']);
        });
        Schema::create('help_misses', function (Blueprint $table) {
            $table->id();
            $table->string('query', 120)->unique();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_at');
        });

        app(HelpCenter::class)->installDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('help_misses');
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_articles');
    }
};
