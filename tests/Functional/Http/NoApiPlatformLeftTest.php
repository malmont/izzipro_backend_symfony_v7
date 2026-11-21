<?php

namespace App\Tests\Functional\Http;

use PHPUnit\Framework\TestCase;

/**
 * API Platform est retiré (lot APIP-RETRAIT, 20/11/2026, décision du propriétaire) : plus aucune classe, configuration ni
 * dépendance ne le cite, et le paquet n'est plus installé. Une ressource se déclare par une route maison
 * (Repository > Service > UseCase > Controller > DTO) ; un `#[ApiResource]` qui reviendrait serait mort (aucune route) ou
 * ramènerait le paquet, ses alertes de sécurité et sa documentation qui nommait tous les champs des entités.
 */
class NoApiPlatformLeftTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const PATTERN = '/ApiPlatform\\\\|#\[ApiResource\b|\bapi_platform\b|api-platform\//';

    public function testNoClassConfigurationOrDependencyCitesApiPlatform(): void
    {
        $found = [];
        foreach (['src', 'config'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || !preg_match('/\.(php|ya?ml|xml)$/', $file->getFilename())) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    // les commentaires qui racontent l'histoire (« avant : API Platform ») restent permis ; seul le code compte
                    if (preg_match(self::PATTERN, $line) && !preg_match('#^\s*(//|\*|/\*|\#(?!\[))#', $line)) {
                        $found[] = substr($file->getPathname(), strlen(self::ROOT) + 1) . ':' . ($n + 1) . ' ' . trim($line);
                    }
                }
            }
        }
        $this->assertSame([], $found, 'mention d\'API Platform dans le code ou la configuration');

        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);
        $this->assertArrayNotHasKey('api-platform/core', $composer['require'] + ($composer['require-dev'] ?? []), 'composer.json');
        $this->assertStringNotContainsString('"api-platform/', (string) file_get_contents(self::ROOT . '/composer.lock'), 'composer.lock');
        $this->assertFalse(class_exists(\ApiPlatform\Metadata\ApiResource::class), 'le paquet n\'est plus installé');
    }

    /** L'essai mord : une ligne de code qui cite API Platform est trouvée, un commentaire ne l'est pas */
    public function testThePatternCatchesCodeButNotComments(): void
    {
        $this->assertMatchesRegularExpression(self::PATTERN, 'use ApiPlatform\Metadata\ApiResource;');
        $this->assertMatchesRegularExpression(self::PATTERN, '#[ApiResource(operations: [])]');
        $this->assertMatchesRegularExpression(self::PATTERN, "    resource: .\n    type: api_platform");
        $this->assertDoesNotMatchRegularExpression('#^\s*(//|\*|/\*|\#(?!\[))#', '#[ApiResource]');
        $this->assertMatchesRegularExpression('#^\s*(//|\*|/\*|\#(?!\[))#', '    // Masquée par API Platform jusqu\'à son retrait');
    }
}
