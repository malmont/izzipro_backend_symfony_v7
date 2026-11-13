<?php

namespace App\Tests\Functional\Platform;

use App\Services\Platform\AdminStepUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou (12/11/2026) : tout écran cité par une étape de cheminement (`'screen' => '…'`) existe dans la table des écrans
 * d'`AdminStepUrlResolver`. Sans son entrée, l'étape n'a plus de lien dans EasyAdmin, sans erreur visible : c'est arrivé au « code
 * court », dont l'entrée s'était retrouvée derrière un commentaire de fin de ligne (lot CHE).
 */
class AdminStepScreensTest extends TestCase
{
    public function testEveryScreenNamedByAStepExists(): void
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $cited = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'AdminStepUrlResolver.php')) {
                continue;
            }
            // écran d'EasyAdmin d'une étape : `['type' => 'screen', 'screen' => '…']` ou l'aide `$screen('…')` des étapes de la gestion
            // (les clés `screen` seules des vignettes, conseils et notifications nomment un écran de l'application, pas d'EasyAdmin)
            if (preg_match_all("/'type'\s*=>\s*'screen',\s*'screen'\s*=>\s*'([a-z_]+)'|\\\$screen\('([a-z_]+)'\)/", (string) file_get_contents($file->getPathname()), $m)) {
                foreach (array_filter(array_merge($m[1], $m[2])) as $key) {
                    $cited[$key][] = substr($file->getPathname(), strlen($root));
                }
            }
        }
        $this->assertArrayHasKey('short_code', $cited, 'le test lit bien les étapes (le code court en est une)');
        $this->assertGreaterThanOrEqual(20, count($cited));
        $missing = array_diff_key($cited, AdminStepUrlResolver::SCREENS);
        $this->assertSame([], array_map(fn (array $files) => implode(', ', array_unique($files)), $missing), 'écran cité par une étape mais absent de AdminStepUrlResolver::SCREENS');
    }
}
