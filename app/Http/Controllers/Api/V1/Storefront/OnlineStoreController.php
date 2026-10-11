<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\DeliveryZone;
use App\Models\Store;
use App\Services\Catalog\ImageStore;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontView;
use App\Services\Storefront\ThemeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Boutique en ligne vue de la gestion : ouverture, thème et réglages, logo, aperçu avant ouverture.
 */
class OnlineStoreController extends Controller
{
    public function __construct(
        private readonly ThemeSettings $themes,
        private readonly ImageStore $images,
        private readonly StorefrontView $view,
        private readonly StorefrontCatalog $catalog,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state($this->currentStore())]);
    }

    /**
     * Ouverture au public (ou fermeture : page « ouvre bientôt »).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $store = $this->currentStore();

        if ($data['enabled'] && ! $this->catalog->products($store)->exists()) {
            throw new BusinessRuleException('Ajoutez au moins un produit actif et visible en ligne avant d\'ouvrir la boutique.', 'enabled');
        }

        $store->forceFill(['storefront_enabled' => $data['enabled']])->save();

        return response()->json(['data' => $this->state($store)]);
    }

    public function updateTheme(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        $store = $this->currentStore();
        $this->themes->update($store, $data['settings']);

        return response()->json(['data' => $this->state($store)]);
    }

    public function activateTheme(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $store = $this->currentStore();
        $this->themes->activate($store, $data['code']);

        return response()->json(['data' => $this->state($store->refresh())]);
    }

    public function heroImage(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.ImageStore::MAX_UPLOAD_KB]], [], ['image' => 'image']);
        $store = $this->currentStore();
        $this->themes->setHeroImage($store, $request->file('image'));

        return response()->json(['data' => $this->state($store)]);
    }

    public function removeHeroImage(): JsonResponse
    {
        $store = $this->currentStore();
        $this->themes->setHeroImage($store, null);

        return response()->json(['data' => $this->state($store)]);
    }

    public function logo(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.ImageStore::MAX_UPLOAD_KB]], [], ['image' => 'logo']);
        $store = $this->currentStore();
        $previous = $store->logo_path;
        $store->forceFill(['logo_path' => $this->images->store($request->file('image'), $store->id, 'branding')['path']])->save();
        if ($previous) {
            $this->images->delete($previous);
        }

        return response()->json(['data' => $this->state($store)]);
    }

    public function removeLogo(): JsonResponse
    {
        $store = $this->currentStore();
        if ($store->logo_path) {
            $this->images->delete($store->logo_path);
            $store->forceFill(['logo_path' => null])->save();
        }

        return response()->json(['data' => $this->state($store)]);
    }

    /**
     * Lien d'aperçu valable une heure (boutique encore fermée), à ouvrir ou à envoyer à un proche.
     */
    public function preview(): JsonResponse
    {
        $store = $this->currentStore();
        $path = URL::temporarySignedRoute('storefront.preview', now()->addHour(), ['boutique' => $store->id], false);

        return response()->json(['data' => ['url' => rtrim($store->url(), '/').$path, 'expires_in' => 3600]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Store $store): array
    {
        $theme = $this->themes->resolve($store);

        return [
            'enabled' => (bool) $store->storefront_enabled,
            'url' => $this->view->baseUrl($store),
            'logo_url' => $store->logo_path ? $this->images->urls($store->logo_path)['thumb'] : null,
            'theme' => [
                'code' => $theme['code'],
                'settings' => $theme['settings'],
                'hero_image_url' => $theme['hero_image_urls']['medium'] ?? null,
            ],
            'themes' => collect(config('themes.themes'))->map(fn ($t, $code) => [
                'code' => $code, 'name' => $t['name'], 'description' => $t['description'], 'defaults' => $t['defaults'],
            ])->values(),
            'schema' => config("themes.themes.{$theme['code']}.settings"),
            'collections' => Collection::forStore($store->id)->orderBy('title')->get(['id', 'title']),
            // Ce qui manque pour une boutique prête à vendre
            'checklist' => [
                'products' => $this->catalog->products($store)->count(),
                'zones' => DeliveryZone::forStore($store->id)->where('is_active', true)->count(),
                'whatsapp' => $store->whatsapp_phone !== null,
                'logo' => $store->logo_path !== null,
            ],
        ];
    }
}
