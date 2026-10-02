<?php

namespace App\Controller\Storage;

use App\Services\SharedMedia\SharedMediaTypes;

use App\Entity\SharedMedia;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class SecureMediaDeliveryController extends AbstractController
{
    /**
     * Secondes pendant lesquelles le navigateur d'un visiteur garde une vidéo affichée dans une page (scène au
     * défilement, fond de section) : sans cela, chaque visite la retélécharge en entier. Cache du navigateur seulement
     * (« private » : jamais un cache partagé), borné par l'expiration du lien. Les documents restent en no-store.
     */
    public const VIDEO_MAX_AGE = 3600;

    private string $privateStorageDir;

    public function __construct(
        string $projectDir,
        private TenantEntityManagerProvider $emProvider
    ) {
        $this->privateStorageDir = rtrim($projectDir, '/') . '/var/storage/private_media';
    }

    #[Route('/media/secure/{accessKey}', name: 'secure_media_delivery', methods: ['GET', 'HEAD'])]
    public function deliver(string $accessKey, Request $request): Response
    {
        // 1. Validation de format du jeton (doit être une chaîne hexadécimale de 64 caractères)
        $cleanKey = trim($accessKey);
        if (strlen($cleanKey) !== 64 || !ctype_xdigit($cleanKey)) {
            return $this->errorPage(Response::HTTP_FORBIDDEN, 'Ce lien de partage n\'est pas valide.');
        }

        // 2. Recherche du média dans la base du tenant courant
        $em = $this->emProvider->getEntityManager();
        /** @var SharedMedia|null $media */
        $media = $em->getRepository(SharedMedia::class)->findOneBy(['accessKey' => $cleanKey]);

        if (!$media || !$media->isPrivate()) {
            return $this->errorPage(Response::HTTP_FORBIDDEN, 'Ce lien de partage n\'est pas valide ou a été révoqué.');
        }

        // 3. Comparaison sécurisée à temps constant (anti-timing attack)
        if (!hash_equals($media->getAccessKey(), $cleanKey)) {
            return $this->errorPage(Response::HTTP_FORBIDDEN, 'Ce lien de partage n\'est pas valide ou a été révoqué.');
        }

        // 4. Vérification d'expiration
        if ($media->isExpired()) {
            return $this->errorPage(Response::HTTP_GONE, 'Ce lien de partage a expiré.');
        }

        // 5. Résolution sécurisée du fichier physique (Protection stricte contre le Path Traversal)
        $rawFilename = (string) $media->getFilename();
        if (str_contains($rawFilename, '..') || str_contains($rawFilename, '/') || str_contains($rawFilename, '\\')) {
            return $this->errorPage(Response::HTTP_NOT_FOUND, 'Ce document est introuvable.');
        }

        $safeFilename = basename($rawFilename);
        $fullPath = $this->privateStorageDir . '/' . $safeFilename;

        $realBase = realpath($this->privateStorageDir);
        $realPath = realpath($fullPath);

        if (!$realBase || !$realPath || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            return $this->errorPage(Response::HTTP_NOT_FOUND, 'Ce document est introuvable.');
        }

        // 6. Incrémentation du compteur de consultations
        try {
            if (!$request->isMethod('HEAD')) {
                $media->incrementDownloadCount();
                $em->flush();
            }
        } catch (\Throwable) {
            // Ne pas bloquer la délivrance en cas de souci d'incrément mineur
        }

        // 7. Envoi de la réponse binaire sécurisée
        $response = new BinaryFileResponse($realPath);

        // Déterminer si le fichier doit être affiché (inline) ou téléchargé (attachment)
        // Affichage dans le navigateur seulement pour les formats sans risque de script (pas de SVG ni de bureautique)
        $isDownload = $request->query->getBoolean('download', false) || !SharedMediaTypes::canDisplayInline($safeFilename);
        $disposition = $isDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE;
        $downloadName = $media->getOriginalFilename() ?: $safeFilename;

        $response->setContentDisposition($disposition, $downloadName);

        // En-têtes de sécurité stricts pour document privé
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        if (!$isDownload && $media->isVideo()) {
            $maxAge = self::VIDEO_MAX_AGE;
            if ($media->getExpiresAt() !== null) {
                $maxAge = max(0, min($maxAge, $media->getExpiresAt()->getTimestamp() - time()));
            }
            $response->headers->set('Cache-Control', sprintf('private, max-age=%d', $maxAge));
            $response->headers->remove('Pragma');
            $response->headers->remove('Expires');
        }

        // Type servi d'après l'extension validée à l'envoi, jamais d'après le contenu (qui pourrait être text/html)
        $mimeType = SharedMediaTypes::servedMimeType($safeFilename);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if (!$isDownload && $mimeType !== 'application/pdf') {
            // Images, vidéos, texte : aucun script ni ressource externe (la visionneuse PDF de Chrome refuse le sandbox)
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }

        // Vidéo déjà en cache et inchangée : 304 sans renvoyer le fichier (Last-Modified posé par BinaryFileResponse)
        if (!$isDownload && $media->isVideo()) {
            $response->isNotModified($request);
        }

        return $response;
    }

    /**
     * Page d'erreur simple pour les destinataires du lien (les exceptions affichaient la page de debug Symfony).
     */
    private function errorPage(int $status, string $message): Response
    {
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Document non disponible</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;background:#f6f7f9;color:#1f2937}main{max-width:32rem;padding:2rem;text-align:center}p{color:#4b5563}</style>'
            . '</head><body><main><h1>Document non disponible</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></main></body></html>';

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
