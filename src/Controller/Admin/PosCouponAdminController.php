<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosCouponService\PosCouponService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Coupons de fabricant à réclamer (F17, 01/11/2026) dans EasyAdmin : rapport par fabricant et période, CSV, marquage « réclamé » par lot */
#[IsGranted('ROLE_ADMIN')]
class PosCouponAdminController extends AbstractController
{
    public function __construct(private readonly PosCouponService $coupons)
    {
    }

    #[Route('/admin/pos/coupons', name: 'admin_pos_coupons', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $filters = ['from' => $request->get('from'), 'to' => $request->get('to'), 'manufacturer' => $request->get('manufacturer'), 'status' => $request->get('status', 'to_claim')];
        $report = null;
        $error = null;
        $done = null;
        try {
            if ($request->isMethod('POST')) {
                /** @var User $user */
                $user = $this->getUser();
                $action = (string) $request->request->get('action', 'report');
                if ($action === 'csv') {
                    $csv = $this->coupons->claimsCsv($this->coupons->claims($filters));

                    return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="coupons-fabricant.csv"', 'Cache-Control' => 'no-store']);
                }
                if ($action === 'mark') {
                    $done = $this->coupons->markClaimed($filters, $user);
                    $filters['status'] = 'claimed';
                }
            }
            $report = $this->coupons->claims($filters);
        } catch (CashRegisterException $e) {
            $error = $e->getMessage();
        }

        return $this->render('admin/pos_coupons/index.html.twig', ['filters' => $filters, 'report' => $report, 'error' => $error, 'done' => $done, 'settings' => $this->coupons->settings()]);
    }
}
