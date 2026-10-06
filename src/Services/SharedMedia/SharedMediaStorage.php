<?php

namespace App\Services\SharedMedia;

use App\Entity\SharedMedia;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers de la médiathèque partagée : enregistrement d'un téléversement (contrôle de l'extension et du type réel,
 * nom aléatoire), déplacement entre stockage public et privé, suppression. Commun à l'administration (EasyAdmin) et
 * à l'API de l'éditeur des landing pages (POST /api/media).
 */
final class SharedMediaStorage
{
    /** Octets : même limite que le formulaire de l'administration */
    public const MAX_SIZE = 104857600;

    private readonly string $publicDir;
    private readonly string $privateDir;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->publicDir = rtrim($projectDir, '/') . '/var/storage/public_bucket/assets/uploads/shared';
        $this->privateDir = rtrim($projectDir, '/') . '/var/storage/private_media';
    }

    /**
     * Enregistre le fichier téléversé pour ce média (remplace l'ancien, et sa vidéo d'origine s'il y en avait une).
     *
     * @throws \InvalidArgumentException format non autorisé ou contenu qui ne correspond pas à l'extension
     */
    public function store(SharedMedia $media, UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        // Extension autorisée ET type réel cohérent (un fichier HTML renommé en .pdf est refusé)
        if (!SharedMediaTypes::isAllowed($extension, $file->getMimeType())) {
            throw new \InvalidArgumentException(sprintf('Le fichier ".%s" (%s) n\'est pas autorisé.', $extension, $file->getMimeType()));
        }

        $media->setMediaType(self::mediaType($extension, $file->getMimeType()));
        $media->setOriginalFilename($originalName);
        // Type normalisé d'après l'extension validée (jamais le type détecté, qui pourrait être text/html)
        $media->setMimeType(SharedMediaTypes::servedMimeType('fichier.' . $extension));
        $media->setFileSize($file->getSize());
        if ($media->isPrivate() && empty($media->getAccessKey())) {
            $media->regenerateAccessKey();
        }

        // Nom unique et sûr (anti-path traversal)
        $safeBase = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file', 0, 30);
        $filename = sprintf('%s_%s.%s', $safeBase, bin2hex(random_bytes(8)), $extension);
        $dir = $media->isPrivate() ? $this->privateDir : $this->publicDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->remove($media);
        $media->setSourceFilename(null)->setScrollStatus(null);
        $file->move($dir, $filename);
        $media->setFilename($filename);
    }

    /** Déplace les fichiers (servi et d'origine) quand la visibilité public / privé a changé */
    public function syncVisibility(SharedMedia $media): void
    {
        [$from, $to] = $media->isPrivate() ? [$this->publicDir, $this->privateDir] : [$this->privateDir, $this->publicDir];
        foreach ($this->filenames($media) as $filename) {
            if (is_file($from . '/' . $filename)) {
                if (!is_dir($to)) {
                    mkdir($to, 0755, true);
                }
                rename($from . '/' . $filename, $to . '/' . $filename);
            }
        }
        if ($media->isPrivate() && empty($media->getAccessKey())) {
            $media->regenerateAccessKey();
        }
    }

    /** Supprime les fichiers du média (servi et d'origine), où qu'ils soient */
    public function remove(SharedMedia $media): void
    {
        foreach ($this->filenames($media) as $filename) {
            foreach ([$this->publicDir, $this->privateDir] as $dir) {
                if (is_file($dir . '/' . $filename)) {
                    @unlink($dir . '/' . $filename);
                }
            }
        }
    }

    public static function mediaType(string $extension, ?string $mimeType): string
    {
        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true) || str_starts_with((string) $mimeType, 'image/') => SharedMedia::TYPE_IMAGE,
            in_array($extension, ['mp4', 'webm', 'mov'], true) || str_starts_with((string) $mimeType, 'video/') => SharedMedia::TYPE_VIDEO,
            default => SharedMedia::TYPE_DOCUMENT,
        };
    }

    /** @return list<string> */
    private function filenames(SharedMedia $media): array
    {
        return array_values(array_filter([basename((string) $media->getFilename()), basename((string) $media->getSourceFilename())]));
    }
}
