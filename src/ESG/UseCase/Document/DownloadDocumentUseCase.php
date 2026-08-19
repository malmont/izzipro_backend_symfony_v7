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

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        string $projectDir
    ) {
        $this->uploadBaseDir = rtrim($projectDir, '/') . '/var/uploads/esg/';
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
            throw new NotFoundHttpException('Fichier physique introuvable sur le serveur.');
        }

        // Path traversal protection
        $realBase = realpath($this->uploadBaseDir);
        $realPath = realpath($fullPath);
        if (!$realBase || !$realPath || !str_starts_with($realPath, $realBase)) {
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
