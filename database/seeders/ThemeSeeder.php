<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;

/**
 * Recopie en base les thèmes définis dans config/themes.php. Idempotent.
 */
class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('themes.themes') as $code => $theme) {
            Theme::updateOrCreate(['code' => $code], [
                'name' => $theme['name'],
                'version' => $theme['version'],
                'settings_schema' => $theme['settings'],
                'is_active' => true,
            ]);
        }
    }
}
