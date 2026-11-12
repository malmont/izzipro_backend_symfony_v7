<?php

namespace App\Support\Services;

use App\Services\TenantConnectionManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pièces jointes de l'assistance (lot 1) : stockage privé du site (var/storage/private_media/support/<site>/<ticket>), jamais d'URL publique ;
 * images et PDF seulement, type vérifié par le CONTENU du fichier (fileinfo), pas par l'extension ; 10 Mo par fichier, 5 par message.
 */
class SupportAttachmentStorage
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_FILES = 5;
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir, private readonly TenantConnectionManager $tenants)
    {
    }

    /** Type réel lu dans le contenu ; null si refusé */
    public static function realMime(UploadedFile $file): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: null;

        return isset(self::MIMES[$mime]) ? $mime : null;
    }

    public function directory(int $ticketId): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/support/' . $code . '/' . $ticketId;
    }

    public function store(int $ticketId, UploadedFile $file, string $mime): string
    {
        $dir = $this->directory($ticketId);
        (new Filesystem())->mkdir($dir, 0750);
        $name = bin2hex(random_bytes(12)) . '.' . self::MIMES[$mime];
        $file->move($dir, $name);

        return $name;
    }

    public function path(int $ticketId, string $storedName): string
    {
        return $this->directory($ticketId) . '/' . basename($storedName);
    }

    public function remove(int $ticketId, string $storedName): void
    {
        (new Filesystem())->remove($this->path($ticketId, $storedName));
    }
}
