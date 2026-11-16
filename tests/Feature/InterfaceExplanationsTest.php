<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Explications (docs/LEXIQUE-INTERFACE.md) : une phrase clé en gras, puis un détail court.
 * Pas d'explication en petit gris clair (text-xs + slate-400/500), et pas de paragraphe de plus de
 * 140 caractères hors de la phrase clé : le reste va dans « En savoir plus » (ExplainMore).
 */
class InterfaceExplanationsTest extends TestCase
{
    /** Écrans diffusés pendant le live : leur propre échelle, vue de loin */
    private const EXCLUS = ['LiveDisplay.vue', 'LiveShowStage.vue', 'LiveOverlay.vue', 'LiveScreen.vue'];

    public function test_les_explications_sont_courtes_et_lisibles(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'vue' || in_array($file->getFilename(), self::EXCLUS, true)) {
                continue;
            }
            $source = $file->getContents();
            // Le contenu replié d'« En savoir plus » peut être plus long
            $body = preg_replace_callback('/<ExplainMore[\s\S]*?<\/ExplainMore>/', fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]), $source);

            preg_match_all('/<p\b((?:[^>"]|"[^"]*")*)>([\s\S]*?)<\/p>/', $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $m) {
                preg_match('/class="([^"]*)"/', $m[1][0], $class);
                $class = $class[1] ?? '';
                $html = preg_replace('/^\s*<b class="[^"]*\bblock\b[^"]*">[\s\S]*?<\/b>/', '', $m[2][0]);
                $text = trim(preg_replace('/\s+/u', ' ', preg_replace(['/<[^>]+>/', '/\{\{[\s\S]*?\}\}/'], [' ', '…'], $html)));
                $length = mb_strlen($text);
                $small = preg_match('/\btext-xs\b/', $class) && preg_match('/\btext-slate-(400|500)\b/', $class);

                if (($small && $length >= 60) || $length > 140) {
                    $line = substr_count(substr($source, 0, $m[0][1]), "\n") + 1;
                    $found[] = $file->getRelativePathname().':'.$line.' ('.$length.' car.) '.mb_substr($text, 0, 80);
                }
            }
        }

        $this->assertSame([], $found, "Explications à raccourcir (phrase clé `explain`, détail `explain-detail`, reste dans ExplainMore) :\n".implode("\n", $found));
    }
}
