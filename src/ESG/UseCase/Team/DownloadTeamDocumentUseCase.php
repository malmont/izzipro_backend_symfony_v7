<?php

namespace App\ESG\UseCase\Team;

use App\ESG\Entity\EsgDocument;
use App\ESG\Service\TeamViewJournal;
use App\ESG\UseCase\Document\DownloadDocumentUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/boussole/team/documents/{id}/download : le fichier d'un document d'une entreprise, pour l'équipe ; même recherche du fichier
 * et mêmes protections que le téléchargement de l'entreprise. Le téléchargement est inscrit au journal des consultations.
 */
class DownloadTeamDocumentUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly DownloadDocumentUseCase $download, private readonly TeamViewJournal $journal)
    {
    }

    public function execute(int $id): BinaryFileResponse
    {
        $document = $this->emProvider->getEntityManager()->getRepository(EsgDocument::class)->find($id);
        if (!$document instanceof EsgDocument) {
            throw new NotFoundHttpException('Document introuvable.');
        }
        $response = $this->download->fileResponse($document);
        $this->journal->viewed($document->getCompany(), TeamViewJournal::DOCUMENT, ['documentId' => $document->getId(), 'name' => $document->getOriginalName()]);

        return $response;
    }
}
