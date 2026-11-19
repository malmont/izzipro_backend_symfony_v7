<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lot IMP-1 : télécharge l'image d'un article importé et la range dans le stockage public des photos d'articles.
 *
 * Sécurité (le fichier d'import vient de l'extérieur : son adresse d'image ne doit jamais faire appeler autre chose par le serveur) :
 * adresse en https, hôte dans `HOSTS` (les serveurs d'images de Shopify), sans identifiants ni port ; les redirections ne sont PAS
 * suivies par le client : chaque nouvelle adresse est revérifiée, trois au plus ; taille plafonnée pendant la lecture ; type réel lu
 * dans le contenu (JPEG, PNG, WebP, GIF), jamais dans le nom ni l'en-tête.
 */
class ImportImageFetcher
{
    public const HOSTS = ['cdn.shopify.com'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_REDIRECTS = 3;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    public const REFUSED = 'IMAGE_HOST_REFUSED';
    public const UNREACHABLE = 'IMAGE_UNREACHABLE';
    public const TOO_BIG = 'IMAGE_TOO_BIG';
    public const NOT_AN_IMAGE = 'IMAGE_TYPE_REFUSED';

    public function __construct(private readonly HttpClientInterface $client, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** L'adresse peut-elle être appelée ? https, hôte permis, ni identifiants ni port */
    public static function allowed(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(mb_strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }

    /** @return array{file: ?string, error: ?string} le nom du fichier rangé, ou la raison de l'échec */
    public function fetch(string $url): array
    {
        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
                if (!self::allowed($url)) {
                    return ['file' => null, 'error' => self::REFUSED];
                }
                $response = $this->client->request('GET', $url, ['max_redirects' => 0, 'timeout' => 10, 'max_duration' => 30, 'headers' => ['Accept' => 'image/*']]);
                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? '';
                    $response->cancel();
                    if ($location === '') {
                        return ['file' => null, 'error' => self::UNREACHABLE];
                    }
                    $url = $location; // revérifiée au tour suivant : une redirection hors de la liste est refusée
                    continue;
                }
                if ($status !== 200) {
                    $response->cancel();

                    return ['file' => null, 'error' => self::UNREACHABLE];
                }
                $content = '';
                foreach ($this->client->stream($response) as $chunk) {
                    $content .= $chunk->getContent();
                    if (strlen($content) > self::MAX_BYTES) {
                        $response->cancel();

                        return ['file' => null, 'error' => self::TOO_BIG];
                    }
                }
                $extension = self::TYPES[(string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content)] ?? null;
                if ($extension === null) {
                    return ['file' => null, 'error' => self::NOT_AN_IMAGE];
                }
                $dir = $this->directory();
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return ['file' => null, 'error' => self::UNREACHABLE];
                }
                $name = 'imp-' . bin2hex(random_bytes(12)) . '.' . $extension;

                return file_put_contents($dir . '/' . $name, $content) === false ? ['file' => null, 'error' => self::UNREACHABLE] : ['file' => $name, 'error' => null];
            }

            return ['file' => null, 'error' => self::REFUSED]; // trop de redirections
        } catch (\Throwable) {
            return ['file' => null, 'error' => self::UNREACHABLE];
        }
    }

    /** Lot G-4 : préfixe d'une image d'une autre boutique du groupe (nom de fichier du dossier des photos, jamais une adresse) */
    public const LOCAL_PREFIX = 'local:';

    /** Une image locale acceptable : `local:` puis un nom de fichier simple (aucun chemin, aucun « .. ») */
    public static function allowedLocal(string $ref): bool
    {
        return str_starts_with($ref, self::LOCAL_PREFIX) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.(jpe?g|png|webp|gif)$/i', substr($ref, strlen(self::LOCAL_PREFIX))) === 1;
    }

    /**
     * Lot G-4 : RECOPIE une photo d'une autre boutique du groupe sous un nom neuf (`imp-…`), jamais partagée : retirer la copie ne touche
     * pas l'original, et l'inverse. Mêmes contrôles qu'un téléchargement (taille, type réel lu dans le contenu).
     *
     * @return array{file: ?string, error: ?string}
     */
    public function copyLocal(string $ref): array
    {
        if (!self::allowedLocal($ref)) {
            return ['file' => null, 'error' => self::REFUSED];
        }
        $dir = $this->directory();
        $path = $dir . '/' . substr($ref, strlen(self::LOCAL_PREFIX));
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, (string) realpath($dir) . '/') || !is_file($real)) {
            return ['file' => null, 'error' => self::UNREACHABLE];
        }
        if (filesize($real) > self::MAX_BYTES) {
            return ['file' => null, 'error' => self::TOO_BIG];
        }
        $content = (string) file_get_contents($real);
        $extension = self::TYPES[(string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content)] ?? null;
        if ($extension === null) {
            return ['file' => null, 'error' => self::NOT_AN_IMAGE];
        }
        $name = 'imp-' . bin2hex(random_bytes(12)) . '.' . $extension;

        return file_put_contents($dir . '/' . $name, $content) === false ? ['file' => null, 'error' => self::UNREACHABLE] : ['file' => $name, 'error' => null];
    }

    /** Retire une image rangée par un import (nom `imp-…` seulement : jamais une autre photo du site) */
    public function remove(string $file): void
    {
        if (preg_match('/^imp-[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $file) === 1) {
            @unlink($this->directory() . '/' . $file);
        }
    }

    private function directory(): string
    {
        return $this->projectDir . '/var/storage/public_bucket/assets/uploads/products';
    }
}
