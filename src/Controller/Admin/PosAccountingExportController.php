<?php

namespace App\Controller\Admin;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\PosReportingUseCase\PosReportingUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Exports comptables de la caisse dans EasyAdmin (F14, 29/10/2026) : période, poste, format → téléchargement ; journal EXPORT_GENERATED */
#[IsGranted('ROLE_ADMIN')]
class PosAccountingExportController extends AbstractController
{
    public function __construct(private readonly PosReportingUseCase $reporting, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    #[Route('/admin/pos/exports', name: 'admin_pos_exports', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $registers = $this->emProvider->getEntityManager()->getRepository(CashRegister::class)->findBy([], ['id' => 'ASC']);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $params = ['from' => $request->get('from', $today), 'to' => $request->get('to', $today), 'cashRegisterId' => $request->get('cashRegisterId') ?: null];
        $preview = null;
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                /** @var User $user */
                $user = $this->getUser();
                $format = (string) $request->request->get('format', 'preview');
                $export = $this->reporting->accounting($user, $params, $format);
                if ($format === 'summary') {
                    return $this->download($this->reporting->summaryCsv($export), sprintf('synthese-caisse_%s_%s.csv', $export['period']['from'], $export['period']['to']));
                }
                if ($format === 'generic' || $format === 'qbo') {
                    return $this->download($this->reporting->entriesCsv($export, $format), sprintf('ecritures-caisse_%s_%s_%s.csv', $format, $export['period']['from'], $export['period']['to']));
                }
                $preview = $export;
            } catch (CashRegisterException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/pos_exports/index.html.twig', ['registers' => $registers, 'params' => $params, 'preview' => $preview, 'error' => $error]);
    }

    private function download(string $content, string $filename): Response
    {
        return new Response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"', 'Cache-Control' => 'no-store']);
    }
}
