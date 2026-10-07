<?php

namespace App\Tests\Fake;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux frontend publiant la configuration des landing pages (manifest.json et fichiers) : contenu fixé par les tests,
 * requêtes enregistrées, aucun appel réseau.
 */
class FakeFrontendConfigHttpClient extends MockHttpClient
{
    public const BASE_URL = 'https://frontend.test/reglable-config/';

    /** @var array<string, string> nom du fichier => contenu publié */
    public static array $published = [];
    /** @var list<string> */
    public static array $requested = [];

    public function __construct()
    {
        parent::__construct(function (string $method, string $url) {
            self::$requested[] = "$method $url";
            $name = str_starts_with($url, self::BASE_URL) ? substr($url, strlen(self::BASE_URL)) : null;

            return isset(self::$published[$name])
                ? new MockResponse(self::$published[$name], ['http_code' => 200])
                : new MockResponse('introuvable', ['http_code' => 404]);
        });
    }

    /**
     * Publie une version : les fichiers donnés remplacent ceux du dépôt (config/landingpage), manifeste calculé
     * (empreintes remplaçables par $hashes pour simuler une empreinte fausse).
     */
    public static function publish(string $version, array $files = [], array $hashes = []): void
    {
        $dir = dirname(__DIR__, 2) . '/config/landingpage';
        $contents = [];
        foreach (['landingpage-reglable.schema.json', 'landingpage-ia-catalogue.json', 'ia-assistant-jeu-essai.md'] as $name) {
            $contents[$name] = $files[$name] ?? file_get_contents("$dir/$name");
        }
        // fichier facultatif : publié seulement s'il est donné
        if (isset($files['ia-libelles-editeur.md'])) {
            $contents['ia-libelles-editeur.md'] = $files['ia-libelles-editeur.md'];
        }
        self::$published = $contents + ['manifest.json' => json_encode([
            'version' => $version,
            'files' => array_merge(array_map(fn ($c) => hash('sha256', $c), $contents), $hashes),
        ])];
    }

    public static function clear(): void
    {
        self::$published = [];
        self::$requested = [];
    }
}
