<?php

namespace App\ESG\UseCase\Document;

use App\ESG\Entity\EsgDocument;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DownloadDocumentUseCase
{
    private string $uploadBaseDir;
    private string $legacyBaseDir;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        string $projectDir
    ) {
        $this->uploadBaseDir = rtrim($projectDir, '/') . '/var/storage/esg/documents/';
        $this->legacyBaseDir = rtrim($projectDir, '/') . '/var/uploads/esg/';
    }

    public function execute(EsgUser $user, int $id): BinaryFileResponse
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new AccessDeniedHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(EsgDocument::class);

        /** @var EsgDocument|null $document */
        $document = $repo->find($id);

        if (!$document) {
            throw new NotFoundHttpException('Document introuvable.');
        }

        // Vérifier ownership
        if ($document->getCompany()->getId() !== $company->getId()) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à ce document.');
        }

        $filePath = $document->getFilePath();
        if (str_starts_with($filePath, 'esg/')) {
            $filePath = substr($filePath, 4);
        }

        $fullPath = $this->uploadBaseDir . $filePath;
        if (!file_exists($fullPath)) {
            $legacyFullPath = $this->legacyBaseDir . $filePath;
            if (file_exists($legacyFullPath)) {
                $fullPath = $legacyFullPath;
            } else {
                throw new NotFoundHttpException('Fichier physique introuvable sur le serveur.');
            }
        }

        // Path traversal protection
        $realBase = realpath($this->uploadBaseDir) ?: '';
        $realLegacyBase = realpath($this->legacyBaseDir) ?: '';
        $realPath = realpath($fullPath);
        $isValidBase = ($realBase && str_starts_with($realPath, $realBase)) ||
                       ($realLegacyBase && str_starts_with($realPath, $realLegacyBase));
        if (!$realPath || !$isValidBase) {
            throw new AccessDeniedHttpException('Accès interdit.');
        }

        $response = new BinaryFileResponse($realPath);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName()
        );

        return $response;
    }
}
