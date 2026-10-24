<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\ArchiveService\PosArchiveService;
use App\Services\CaisseService\CashRegisterException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Archive annuelle des données de caisse (A1, 30/10/2026) : ZIP (JSON, PDF, SHA256SUMS), responsable ; journal ARCHIVE_EXPORTED */
class PosArchiveController extends AbstractController
{
    public function __construct(private readonly PosArchiveService $archive)
    {
    }

    /** Téléchargement de l'archive de l'exercice ; en-têtes X-Archive-Sha256 et X-Archive-Counts */
    #[Route('/api/pos/archive/{year}', name: 'pos_archive_download', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function download(int $year): Response
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new CashRegisterException('UNAUTHENTICATED', 'Utilisateur non connecté', 401);
            }
            $a = $this->archive->build($user, $year);
            $response = new BinaryFileResponse($a['path']);
            $response->deleteFileAfterSend(true);
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('X-Archive-Sha256', $a['sha256']);
            $response->headers->set('X-Archive-Counts', json_encode($a['counts']));
            $response->headers->set('X-Archive-Chain-Valid', $a['chainValid'] ? '1' : '0');
            $response->headers->set('Cache-Control', 'no-store');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $a['filename']);

            return $response;
        } catch (CashRegisterException $e) {
            return new JsonResponse($e->toBody(), $e->status);
        }
    }
}
