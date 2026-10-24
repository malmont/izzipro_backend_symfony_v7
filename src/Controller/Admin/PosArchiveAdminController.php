<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Services\ArchiveService\PosArchiveService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Archive annuelle de la caisse dans EasyAdmin (A1, 30/10/2026) : choix de l'exercice → ZIP ; journal ARCHIVE_EXPORTED */
#[IsGranted('ROLE_ADMIN')]
class PosArchiveAdminController extends AbstractController
{
    public function __construct(private readonly PosArchiveService $archive, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    #[Route('/admin/pos/archive', name: 'admin_pos_archive', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $years = array_map('intval', $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT EXTRACT(YEAR FROM order_date)::int AS y FROM "order" WHERE order_source_id = 2 ORDER BY y DESC'));
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                /** @var User $user */
                $user = $this->getUser();
                $a = $this->archive->build($user, $request->request->get('year'));
                $response = new BinaryFileResponse($a['path']);
                $response->deleteFileAfterSend(true);
                $response->headers->set('Content-Type', 'application/zip');
                $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $a['filename']);

                return $response;
            } catch (CashRegisterException $e) {
                $error = $e->getMessage();
            }
        }
        $journal = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative("SELECT occurred_at, payload FROM pos_audit_log WHERE type = 'ARCHIVE_EXPORTED' ORDER BY id DESC LIMIT 20");

        return $this->render('admin/pos_archive/index.html.twig', ['years' => $years ?: [(int) date('Y')], 'error' => $error, 'journal' => array_map(fn ($r) => ['at' => $r['occurred_at'], 'data' => (json_decode($r['payload'], true)['data'] ?? [])], $journal)]);
    }
}
