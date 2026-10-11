<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Menu;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menus de la boutique en ligne : principal (en-tête) et pied de page. Tant qu'un menu n'est pas enregistré,
 * la boutique affiche un menu par défaut (voir StorefrontView::menu).
 */
class MenuController extends Controller
{
    public const HANDLES = ['main', 'footer'];

    public const MAX_ITEMS = 12;

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $saved = Menu::forStore($store->id)->get()->keyBy('handle');

        return response()->json(['data' => collect(self::HANDLES)->mapWithKeys(fn ($handle) => [$handle => [
            'custom' => $saved->has($handle),
            'items' => $saved->get($handle)?->items ?? [],
        ]])]);
    }

    public function update(Request $request, string $handle): JsonResponse
    {
        abort_unless(in_array($handle, self::HANDLES, true), 404);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.label' => ['required', 'string', 'max:40'],
            'items.*.type' => ['required', Rule::in(['home', 'products', 'collection', 'page', 'url'])],
            'items.*.target' => ['nullable'],
        ], [], ['items.*.label' => 'libellé', 'items.*.type' => 'type de lien']);

        $store = $this->currentStore();
        $items = [];
        foreach (array_values($data['items']) as $i => $item) {
            $target = $item['target'] ?? null;
            $valid = match ($item['type']) {
                'collection' => Collection::forStore($store->id)->whereKey((int) $target)->exists(),
                'page' => Page::forStore($store->id)->whereKey((int) $target)->exists(),
                'url' => is_string($target) && mb_strlen($target) <= 255 && preg_match('#^(https?://|/)#', $target),
                default => true,
            };
            if (! $valid) {
                throw new BusinessRuleException("Lien « {$item['label']} » : destination invalide.", "items.{$i}.target");
            }
            $items[] = ['label' => trim($item['label']), 'type' => $item['type'], 'target' => in_array($item['type'], ['collection', 'page'], true) ? (int) $target : ($item['type'] === 'url' ? $target : null)];
        }

        Menu::forStore($store->id)->updateOrCreate(['store_id' => $store->id, 'handle' => $handle], ['items' => $items]);

        return $this->index();
    }

    /**
     * Retour au menu par défaut.
     */
    public function destroy(string $handle): JsonResponse
    {
        abort_unless(in_array($handle, self::HANDLES, true), 404);
        Menu::forStore($this->currentStore()->id)->where('handle', $handle)->delete();

        return $this->index();
    }
}
