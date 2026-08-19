<?php

namespace App\ESG\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class DocumentStorageService
{
    private string $uploadBaseDir;

    public function __construct(string $projectDir)
    {
        // Stockage dans var/uploads/esg/
        $this->uploadBaseDir = rtrim($projectDir, '/') . '/var/uploads/esg/';
    }

    public function store(UploadedFile $file, int $companyId, string $code): array
    {
        // 1. Crée le dossier var/uploads/esg/{companyId}/ si inexistant
        $targetDir = $this->uploadBaseDir . $companyId . '/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // 2. Génère un nom unique : {code}_{timestamp}_{random8chars}.{ext}
        $timestamp = time();
        $random = substr(bin2hex(random_bytes(4)), 0, 8);
        $ext = $file->getClientOriginalExtension();
        if (empty($ext)) {
            $ext = $file->guessExtension() ?? 'bin';
        }
        $storedName = sprintf('%s_%d_%s.%s', $code, $timestamp, $random, $ext);

        // 3. Déplace le fichier via $file->move()
        $file->move($targetDir, $storedName);

        // 4. Retourne les métadonnées
        return [
            'storedName' => $storedName,
            'filePath'   => 'esg/' . $companyId . '/' . $storedName,
            'fileSize'   => filesize($targetDir . $storedName),
            'mimeType'   => mime_content_type($targetDir . $storedName) ?: $file->getClientMimeType()
        ];
    }

    public function delete(string $filePath): void
    {
        // Path traversal protection
        if (str_contains($filePath, '..')) {
            return;
        }

        // Si le chemin commence par esg/, on le retire pour correspondre à $this->uploadBaseDir
        if (str_starts_with($filePath, 'esg/')) {
            $filePath = substr($filePath, 4);
        }

        $fullPath = $this->uploadBaseDir . $filePath;

        // Validation stricte du répertoire de base
        $realBase = realpath($this->uploadBaseDir);
        $realPath = realpath($fullPath);
        
        if ($realBase && $realPath && str_starts_with($realPath, $realBase)) {
            if (file_exists($realPath)) {
                unlink($realPath);
            }
        }
    }

    public function getPublicUrl(string $filePath): string
    {
        return '/api/boussole/documents/download?path=' . urlencode($filePath);
    }
}
