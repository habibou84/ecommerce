<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Échelle des tailles de texte (docs/LEXIQUE-INTERFACE.md) : les écrans n'utilisent que les six tailles
 * de la charte, jamais une taille écrite à la main comme text-[15px]. Les tailles relatives à l'écran
 * (text-[9vh], text-[38vmin]) restent permises : elles servent aux écrans du live vus de loin.
 */
class InterfaceTypographyTest extends TestCase
{
    public function test_les_ecrans_n_ecrivent_pas_de_taille_de_texte_a_la_main(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            foreach (preg_split('/\R/', $file->getContents()) as $i => $line) {
                if (preg_match_all('/\btext-\[\d+(?:\.\d+)?(?:px|rem|em)\]/', $line, $m)) {
                    $found[] = $file->getRelativePathname().':'.($i + 1).' '.implode(' ', $m[0]);
                }
            }
        }

        $this->assertSame([], $found, "Tailles écrites à la main : utiliser text-xs, text-sm, text-base, text-lg, text-2xl ou text-3xl.\n".implode("\n", $found));
    }
}
