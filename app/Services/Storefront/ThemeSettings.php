<?php

namespace App\Services\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Models\Collection;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Thème actif d'une boutique et ses réglages (fusionnés avec les valeurs par défaut du thème).
 */
class ThemeSettings
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * Thème et réglages prêts pour les gabarits.
     *
     * @return array{code: string, name: string, layout: array<string, string>, settings: array<string, mixed>, css: string, hero_image_urls: ?array}
     */
    public function resolve(Store $store): array
    {
        $storeTheme = $this->current($store);
        $code = $storeTheme->theme->code;
        $definition = config("themes.themes.{$code}") ?? config('themes.themes.'.config('themes.default'));
        $settings = array_merge($definition['defaults'], array_intersect_key($storeTheme->settings ?? [], $definition['defaults']));

        return [
            'code' => $code,
            'name' => $definition['name'],
            'layout' => $definition['layout'],
            'settings' => $settings,
            'css' => $this->css($settings),
            'hero_image_urls' => $settings['hero_image'] ? $this->images->urls($settings['hero_image']) : null,
        ];
    }

    /**
     * Réglages du thème de la boutique ; le thème par défaut est créé au premier besoin.
     */
    public function current(Store $store): StoreTheme
    {
        $storeTheme = $store->active_theme_id ? StoreTheme::forStore($store->id)->with('theme')->find($store->active_theme_id) : null;

        return $storeTheme ?? $this->activate($store, config('themes.default'));
    }

    /**
     * Change de thème en gardant les réglages communs (couleurs, textes) déjà saisis.
     */
    public function activate(Store $store, string $code): StoreTheme
    {
        $theme = Theme::where('code', $code)->where('is_active', true)->first()
            ?? throw new BusinessRuleException('Thème inconnu.', 'theme');

        return DB::transaction(function () use ($store, $theme) {
            $previous = $store->active_theme_id ? StoreTheme::forStore($store->id)->find($store->active_theme_id) : null;
            $storeTheme = StoreTheme::forStore($store->id)->firstOrCreate(
                ['store_id' => $store->id, 'theme_id' => $theme->id],
                ['settings' => $this->carryOver($previous?->settings ?? [], $theme->code), 'published_at' => now()],
            );
            $store->forceFill(['active_theme_id' => $storeTheme->id])->save();

            return $storeTheme->setRelation('theme', $theme);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Store $store, array $input): StoreTheme
    {
        $storeTheme = $this->current($store);
        $schema = collect(config("themes.themes.{$storeTheme->theme->code}.settings"))->keyBy('key');
        $settings = $storeTheme->settings ?? [];

        foreach ($input as $key => $value) {
            $field = $schema->get($key);
            if ($field === null || $field['type'] === 'image') {
                continue;
            }
            $settings[$key] = $this->clean($store, $field, $value);
        }

        $storeTheme->update(['settings' => $settings, 'published_at' => now()]);

        return $storeTheme;
    }

    public function setHeroImage(Store $store, ?UploadedFile $file): StoreTheme
    {
        $storeTheme = $this->current($store);
        $settings = $storeTheme->settings ?? [];

        if (! empty($settings['hero_image'])) {
            $this->images->delete($settings['hero_image']);
        }
        $settings['hero_image'] = $file ? $this->images->store($file, $store->id, 'theme')['path'] : null;
        $storeTheme->update(['settings' => $settings]);

        return $storeTheme;
    }

    private function clean(Store $store, array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value)
                : throw new BusinessRuleException("{$field['label']} : couleur invalide (format #RRGGBB).", $field['key']),
            'select' => array_key_exists($value, $field['options']) ? $value
                : throw new BusinessRuleException("{$field['label']} : choix invalide.", $field['key']),
            'boolean' => (bool) $value,
            'collection' => $value === null || $value === '' ? null
                : (Collection::forStore($store->id)->whereKey($value)->exists() ? (int) $value
                    : throw new BusinessRuleException('Collection introuvable.', $field['key'])),
            default => $value === null || trim((string) $value) === '' ? null : mb_substr(trim((string) $value), 0, $field['max'] ?? 255),
        };
    }

    private function carryOver(array $settings, string $code): array
    {
        // Les couleurs et la police restent celles du nouveau thème ; les textes et choix de contenu sont repris
        return array_diff_key($settings, array_flip(['primary_color', 'background_color', 'font']))
            + config("themes.themes.{$code}.defaults");
    }

    /**
     * Variables CSS du thème (couleur principale, texte lisible dessus, fond, police).
     */
    private function css(array $settings): string
    {
        $primary = $settings['primary_color'];
        [$r, $g, $b] = sscanf($primary, '#%02x%02x%02x');
        $contrast = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#ffffff';
        $font = match ($settings['font']) {
            'serif' => "Georgia, 'Times New Roman', serif",
            'rounded' => "ui-rounded, 'SF Pro Rounded', 'Segoe UI', system-ui, sans-serif",
            default => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        };

        return "--brand: {$primary}; --brand-contrast: {$contrast}; --brand-soft: rgba({$r}, {$g}, {$b}, 0.08); --page-bg: {$settings['background_color']}; --heading-font: {$font};";
    }
}
