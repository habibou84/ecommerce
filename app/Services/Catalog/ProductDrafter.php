<?php

namespace App\Services\Catalog;

use App\Models\Collection;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Assistant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Fiche produit proposée par l'assistant IA à partir de photos : titre, description, options visibles (tailles,
 * couleurs), étiquettes, collections existantes, référencement. Le prix et le stock restent au marchand ; rien
 * n'est enregistré avant qu'il valide la fiche.
 */
class ProductDrafter
{
    private const SYSTEM = <<<'TXT'
    Tu aides des commerçants d'Afrique de l'Ouest (Côte d'Ivoire surtout) à créer les fiches produits de leur boutique en ligne.
    À partir des photos (et de la note du commerçant s'il y en a une), rédige une fiche en français simple et vendeur :
    - title : nom court et précis, comme le chercherait un client (ex. « Robe wax évasée manches courtes »), 70 caractères au plus ;
    - description : 3 à 6 phrases courtes ou une liste de points (matière apparente, coupe, usage, conseils d'entretien si évidents). N'invente pas de caractéristique invisible (composition exacte, dimensions, origine) : si la note du commerçant la donne, reprends-la ;
    - brand : la marque seulement si elle est lisible sur la photo ou donnée par le commerçant, sinon null ;
    - options : les choix que le client devra faire (Taille, Couleur, Pointure…), avec les valeurs visibles ou données par le commerçant ; aucune option si le produit est unique. Trois options au plus ;
    - tags : 3 à 6 mots-clés courts ;
    - collections : uniquement des noms pris dans la liste des collections de la boutique, si le produit y a clairement sa place ;
    - seo_title (70 caractères au plus) et seo_description (160 caractères au plus) pour Google et le partage WhatsApp ;
    - to_check : ce que le commerçant doit vérifier ou compléter (prix, tailles disponibles, couleur exacte…), en une phrase.
    Ne donne jamais de prix.
    TXT;

    public function __construct(private readonly Assistant $assistant) {}

    /**
     * @param  list<UploadedFile>  $photos
     * @return array<string, mixed>
     */
    public function draft(Store $store, User $user, array $photos, ?string $note = null): array
    {
        $collections = Collection::forStore($store->id)->orderBy('title')->limit(100)->get(['id', 'title']);

        $content = array_map(fn (UploadedFile $photo) => $this->assistant->image($photo), $photos);
        $content[] = ['type' => 'text', 'text' => implode("\n", array_filter([
            'Boutique : '.$store->name,
            $collections->isEmpty() ? 'La boutique n\'a pas encore de collections.' : 'Collections de la boutique : '.$collections->pluck('title')->implode(' ; '),
            filled($note) ? 'Note du commerçant : '.Str::limit(trim($note), 1000) : null,
        ]))];

        $draft = $this->assistant->run($store, $user, 'product_draft', self::SYSTEM, $content, self::schema());

        $wanted = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $draft['collections'] ?? []);
        $options = array_slice(array_values(array_filter(array_map(fn ($o) => [
            'name' => Str::limit(trim((string) ($o['name'] ?? '')), 40, ''),
            'values' => array_slice(array_values(array_unique(array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 40, ''), $o['values'] ?? [])))), 0, 30),
        ], $draft['options'] ?? []), fn ($o) => $o['name'] !== '' && $o['values'] !== [])), 0, 3);

        return [
            'title' => Str::limit(trim((string) ($draft['title'] ?? '')), 150, ''),
            'description' => trim((string) ($draft['description'] ?? '')),
            'brand' => filled($draft['brand'] ?? null) ? Str::limit(trim($draft['brand']), 80, '') : null,
            'options' => $options,
            'tags' => array_slice(array_values(array_filter(array_map(fn ($t) => Str::limit(trim((string) $t), 40, ''), $draft['tags'] ?? []))), 0, 8),
            'collection_ids' => $collections->filter(fn ($c) => in_array(mb_strtolower($c->title), $wanted, true))->pluck('id')->values()->all(),
            'seo_title' => Str::limit(trim((string) ($draft['seo_title'] ?? '')), 70, ''),
            'seo_description' => Str::limit(trim((string) ($draft['seo_description'] ?? '')), 160, ''),
            'to_check' => filled($draft['to_check'] ?? null) ? trim($draft['to_check']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'brand' => ['type' => ['string', 'null']],
                'options' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'values' => $strings],
                    'required' => ['name', 'values'],
                    'additionalProperties' => false,
                ]],
                'tags' => $strings,
                'collections' => $strings,
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
                'to_check' => ['type' => ['string', 'null']],
            ],
            'required' => ['title', 'description', 'brand', 'options', 'tags', 'collections', 'seo_title', 'seo_description', 'to_check'],
            'additionalProperties' => false,
        ];
    }
}
