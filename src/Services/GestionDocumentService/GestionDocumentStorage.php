<?php

namespace App\Services\GestionDocumentService;

use App\Entity\GestionDocument;
use App\Services\TenantConnectionManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers des documents de la gestion (lot L1) : `var/storage/private_media/gestion-documents/<code du site>/<id>/`, jamais servis
 * publiquement (route `GET /api/gestion/documents/{id}/fichier/{n}`, `ROLE_ADMIN`). Pages `page-<n>.<ext>`, photos de ligne `line-<n>.<ext>`.
 */
class GestionDocumentStorage
{
    public const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir, private readonly TenantConnectionManager $tenants)
    {
    }

    public function directory(GestionDocument $document): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/gestion-documents/' . $code . '/' . $document->getId();
    }

    /** Copie un fichier reçu sous le nom donné (sans extension) ; rend le nom de fichier stocké */
    public function store(GestionDocument $document, UploadedFile $file, string $baseName, string $mime): string
    {
        $dir = $this->directory($document);
        (new Filesystem())->mkdir($dir, 0750);
        $name = $baseName . '.' . (self::EXTENSIONS[$mime] ?? 'bin');
        $file->move($dir, $name);

        return $name;
    }

    public function path(GestionDocument $document, string $file): string
    {
        return $this->directory($document) . '/' . basename($file);
    }

    public function read(GestionDocument $document, string $file): ?string
    {
        $path = $this->path($document, $file);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function removeFile(GestionDocument $document, string $file): void
    {
        (new Filesystem())->remove($this->path($document, $file));
    }

    public function removeAll(GestionDocument $document): void
    {
        (new Filesystem())->remove($this->directory($document));
    }
}
