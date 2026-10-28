<?php

namespace App\Tests\Functional\Cache;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou des conventions d'étiquettes du cache (lot 1, 28/10/2026) : une étiquette ne s'écrit qu'à travers le registre
 * App\Cache\CacheTags et TenantCacheService. Les fichiers listés ci-dessous posent encore des étiquettes à la main
 * (`$item->tag(…)`, `invalidateTags([...])` avec un nom brut) : chaque lot du chantier retire les siens, et la liste ne
 * peut que diminuer. Un fichier nouveau qui pose une étiquette à la main fait échouer la suite.
 */
class CacheTagConventionTest extends TestCase
{
    /** Posent des étiquettes par nature : le service lui-même et l'annuaire des sites (étiquette `tenants`, base maître) */
    private const ALLOWED = [
        'src/Services/TenantCacheService.php',
        'src/Services/TenantConnectionManager.php',
        'src/Cache/CacheInvalidationQueue.php',
        'src/Command/TenantCacheClearCommand.php',
        // annuaire des sites (base maître, étiquette `tenants` sur cache.app) : hors du registre par nature
        'src/Controller/TenantSetupController/TenantController.php',
    ];

    /** `$item->tag(…)` encore écrit dans une fonction de calcul : plus aucun depuis le lot 4 (28/10/2026) */
    private const LEGACY_TAG_FILES = [];

    /** `invalidateTags([...])` à la main : plus aucun depuis le lot 4 ; une écriture en SQL direct passe par invalidateComponents() */
    private const LEGACY_INVALIDATE_FILES = [];

    public function testNoNewHandWrittenTag(): void
    {
        $offenders = array_diff($this->filesContaining('->tag('), self::ALLOWED, self::LEGACY_TAG_FILES);
        $this->assertSame([], array_values($offenders), 'étiquette posée à la main : passer par TenantCacheService::get() et CacheTags');
    }

    public function testNoNewHandWrittenInvalidation(): void
    {
        $offenders = array_diff($this->filesContaining('invalidateTags('), self::ALLOWED, self::LEGACY_INVALIDATE_FILES);
        $this->assertSame([], array_values($offenders), 'invalidation à la main : déclarer l\'entité dans CacheTags, l\'écriture Doctrine suffit');
    }

    public function testTheLegacyListsOnlyShrink(): void
    {
        if (self::LEGACY_TAG_FILES === [] && self::LEGACY_INVALIDATE_FILES === []) {
            $this->expectNotToPerformAssertions(); // chantier terminé : plus rien à retirer
        }
        $tagFiles = $this->filesContaining('->tag(');
        foreach (self::LEGACY_TAG_FILES as $file) {
            $this->assertContains($file, $tagFiles, $file . ' ne pose plus d\'étiquette à la main : le retirer de LEGACY_TAG_FILES');
        }
        $invalidateFiles = $this->filesContaining('invalidateTags(');
        foreach (self::LEGACY_INVALIDATE_FILES as $file) {
            $this->assertContains($file, $invalidateFiles, $file . ' n\'invalide plus à la main : le retirer de LEGACY_INVALIDATE_FILES');
        }
    }

    /** @return list<string> chemins relatifs, triés, des fichiers de src/ qui contiennent le texte */
    private function filesContaining(string $needle): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }
}
