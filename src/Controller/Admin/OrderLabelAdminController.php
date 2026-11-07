<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Services\ShippingService\AdminLabelPurchaseService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Acheter l'étiquette » depuis la commande (lot 2 Livraison, 07/11/2026) : colis calculés, tarifs avec frais de service et solde
 * prépayé (mode plateforme) ou tarifs du compte EasyPost du site, choix du tarif, achat, puis commande expédiée avec suivi et courriel.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderLabelAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_order_label';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly AdminLabelPurchaseService $labels, private readonly AdminUrlGenerator $adminUrls)
    {
    }

    #[Route('/admin/orders/{id}/etiquette', name: 'admin_order_label', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function label(int $id, Request $request): Response
    {
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');
            } else {
                [$account, $service] = array_pad(explode('|', (string) $request->request->get('rate', ''), 2), 2, '');
                $result = $this->labels->buy($order, $account, $service, $request->request->get('notify') === '1', $this->getUser()?->getUserIdentifier(), $request->getSchemeAndHttpHost());
                if ($result['status'] === 200) {
                    $b = $result['body'];
                    $this->addFlash('success', sprintf('Étiquette(s) achetée(s) pour la commande %s : %s (%s %s%s). Suivi : %s. %s', $order->getReference(), $service, number_format($b['totalCents'] / 100, 2, ',', ' '), $b['currency'], $b['feeCents'] > 0 ? ', dont ' . number_format($b['feeCents'] / 100, 2, ',', ' ') . ' de frais' : '', implode(', ', array_column($b['labels'], 'trackingCode')), $b['notified'] ? 'Le client a reçu le courriel avec le suivi.' : 'Aucun courriel envoyé au client.'));

                    return $this->redirect($this->adminUrls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl());
                }
                $this->addFlash('danger', $result['body']['message']);
            }

            return $this->redirect($this->selfUrl($id));
        }
        $quote = $this->labels->quote($order);
        $labels = [];
        if ($order->getShippingOrder() !== null) {
            foreach ($order->getShippingOrder()->getParcels() as $parcel) {
                if ($parcel->getShippingLabel() !== null) {
                    $labels[] = ['url' => $parcel->getShippingLabel()->getLabelUrl(), 'tracking' => $parcel->getShippingLabel()->getTrackingCode(), 'index' => $parcel->getIndex()];
                }
            }
        }

        return $this->render('admin/orders/etiquette.html.twig', [
            'order' => $order, 'quote' => $quote, 'labels' => $labels, 'csrfToken' => self::CSRF_ID,
            'backUrl' => $this->adminUrls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl(),
            'shipUrl' => $this->adminUrls->unsetAll()->setRoute('admin_order_ship', ['id' => $id])->generateUrl(),
            'shippingUrl' => $this->adminUrls->unsetAll()->setRoute('admin_livraison')->generateUrl(),
        ]);
    }

    private function selfUrl(int $id): string
    {
        try {
            return $this->adminUrls->unsetAll()->setRoute('admin_order_label', ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_order_label', ['id' => $id]);
        }
    }
}
