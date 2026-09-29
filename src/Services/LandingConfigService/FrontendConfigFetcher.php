<?php

namespace App\Services\LandingConfigService;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lit la configuration publiée par le frontend sous FRONTEND_CONFIG_URL (et nulle part ailleurs) :
 * manifest.json { "version", "files": { "<nom>": "<sha256>" } } puis les fichiers, dont l'empreinte est vérifiée.
 */
final class FrontendConfigFetcher
{
    public const TIMEOUT = 20.0;
    public const MAX_MANIFEST_BYTES = 65536;
    public const MAX_FILE_BYTES = 20971520;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::FRONTEND_CONFIG_URL)%')]
        private readonly ?string $baseUrl
    ) {
    }

    public function isConfigured(): bool
    {
        return (bool) preg_match('#^https?://#', (string) $this->baseUrl);
    }

    /**
     * @return array{version: string, files: array<string, string>}
     * @throws LandingConfigException 503 non configuré, 502 injoignable, 422 manifeste invalide
     */
    public function manifest(): array
    {
        $data = json_decode($this->download('manifest.json', self::MAX_MANIFEST_BYTES), true);
        $errors = [];
        $version = $data['version'] ?? null;
        if (!is_string($version) || !preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version)) {
            $errors[] = ['path' => 'version', 'message' => 'texte de 1 à 64 caractères (lettres, chiffres, . _ + -) attendu'];
        }
        $files = is_array($data['files'] ?? null) ? $data['files'] : [];
        foreach (LandingConfigStore::FILES as $file) {
            if (!is_string($files[$file] ?? null) || !preg_match('/^[A-Fa-f0-9]{64}$/', $files[$file])) {
                $errors[] = ['path' => "files.$file", 'message' => 'empreinte sha256 (64 caractères hexadécimaux) attendue'];
            }
        }
        foreach (array_diff(array_keys($files), LandingConfigStore::FILES) as $unknown) {
            $errors[] = ['path' => "files.$unknown", 'message' => 'fichier inconnu'];
        }
        if (!is_array($data) || $errors) {
            throw new LandingConfigException(422, 'Manifeste invalide', 'Le manifeste publié par le frontend est invalide.', $errors ?: [['path' => '', 'message' => 'objet JSON attendu']]);
        }

        return ['version' => $version, 'files' => array_map('strtolower', array_intersect_key($files, array_flip(LandingConfigStore::FILES)))];
    }

    /**
     * Télécharge les fichiers du manifeste et vérifie leur empreinte.
     *
     * @param array{version: string, files: array<string, string>} $manifest
     * @return array<string, string> nom => contenu
     * @throws LandingConfigException 422 si une empreinte ne correspond pas
     */
    public function files(array $manifest): array
    {
        $contents = [];
        $errors = [];
        foreach ($manifest['files'] as $file => $expected) {
            $contents[$file] = $this->download($file, self::MAX_FILE_BYTES);
            $actual = hash('sha256', $contents[$file]);
            if (!hash_equals($expected, $actual)) {
                $errors[] = ['path' => "files.$file", 'message' => 'empreinte différente du manifeste', 'expected' => $expected, 'actual' => $actual];
            }
        }
        if ($errors) {
            throw new LandingConfigException(422, 'Empreinte invalide', 'Un fichier téléchargé ne correspond pas à l\'empreinte du manifeste : rien n\'a été activé.', $errors);
        }

        return $contents;
    }

    private function download(string $name, int $maxBytes): string
    {
        if (!$this->isConfigured()) {
            throw new LandingConfigException(503, 'Synchronisation non configurée', 'FRONTEND_CONFIG_URL n\'est pas définie (adresse http(s) du dossier publié par le frontend).');
        }
        $url = rtrim((string) $this->baseUrl, '/') . '/' . $name;
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 3, 'max_redirects' => 0]);
            if ($response->getStatusCode() !== 200) {
                throw new LandingConfigException(502, 'Frontend injoignable', sprintf('%s : réponse HTTP %d.', $name, $response->getStatusCode()));
            }
            $body = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new LandingConfigException(422, 'Fichier trop volumineux', sprintf('%s dépasse %d octets.', $name, $maxBytes));
                }
            }

            return $body;
        } catch (LandingConfigException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LandingConfigException(502, 'Frontend injoignable', sprintf('%s : %s', $name, $e->getMessage()));
        }
    }
}
