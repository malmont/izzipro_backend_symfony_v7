<?php

namespace App\ESG\UseCase\Document;

use App\ESG\DTO\Input\UploadDocumentInputDTO;
use App\ESG\DTO\Output\EsgDocumentOutputDTO;
use App\ESG\Entity\EsgDocument;
use App\ESG\Enum\DomainEnum;
use App\ESG\Entity\EsgUser;
use App\ESG\Service\DocumentStorageService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadDocumentUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly DocumentStorageService $storageService
    ) {
    }

    public function execute(EsgUser $user, UploadedFile $file, UploadDocumentInputDTO $dto): EsgDocumentOutputDTO
    {
        // VALIDATION FICHIER
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $maxSize = 10 * 1024 * 1024; // 10 Mo

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé.');
        }
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('Fichier trop volumineux (max 10 Mo).');
        }

        // RÉCUPÉRER L'ENTREPRISE
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(EsgDocument::class);

        // UPSERT : chercher un document existant avec ce code
        $existing = $repo->findByCompanyAndCode($company, $dto->code);
        if ($existing) {
            // Supprimer l'ancien fichier physique
            $this->storageService->delete($existing->getFilePath());
            // Supprimer l'entrée en base
            $repo->deleteByCompanyAndCode($company, $dto->code);
        }

        // STOCKER LE NOUVEAU FICHIER
        $stored = $this->storageService->store($file, $company->getId(), $dto->code);

        // CRÉER L'ENTITÉ
        $domain = DomainEnum::from($dto->domain);
        $document = new EsgDocument();
        $document->setCode($dto->code);
        $document->setDomain($domain);
        $document->setOriginalName($file->getClientOriginalName());
        $document->setStoredName($stored['storedName']);
        $document->setFilePath($stored['filePath']);
        $document->setFileSize($stored['fileSize']);
        $document->setMimeType($stored['mimeType']);
        $document->setCompany($company);
        $document->setUploadedAt(new \DateTimeImmutable());

        $repo->save($document);

        // METTRE À JOUR uploadedDocumentCodes SUR EsgCompany
        $company->addUploadedDocumentCode($dto->code);
        $em->persist($company);
        $em->flush();

        $downloadUrl = '/api/boussole/documents/download/' . $document->getId();

        return new EsgDocumentOutputDTO($document, $downloadUrl);
    }
}
