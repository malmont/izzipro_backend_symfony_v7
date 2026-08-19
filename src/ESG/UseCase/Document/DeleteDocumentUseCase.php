<?php

namespace App\ESG\UseCase\Document;

use App\ESG\Entity\EsgDocument;
use App\ESG\Entity\EsgUser;
use App\ESG\Service\DocumentStorageService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteDocumentUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly DocumentStorageService $storageService
    ) {
    }

    public function execute(EsgUser $user, int $id): void
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

        // Vérifier que le document appartient bien à l'entreprise
        if ($document->getCompany()->getId() !== $company->getId()) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à ce document.');
        }

        // Supprimer le fichier physique
        $this->storageService->delete($document->getFilePath());

        // Supprimer l'entrée en base
        $code = $document->getCode();
        $em->remove($document);

        // Mettre à jour les codes de documents téléversés sur l'entreprise
        $company->removeUploadedDocumentCode($code);
        $em->persist($company);
        
        $em->flush();
    }
}
