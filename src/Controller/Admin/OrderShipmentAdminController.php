<?php

namespace App\Controller\Admin;

use App\Services\OrderService\OrderPresenter;
use App\Services\OrderService\OrderShipmentService;
use App\UseCase\OrderUseCase\AdminOrderShipmentUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « J'expédie moi-même » (livraison, lot 1, 07/11/2026) : page d'expédition d'une commande (transporteur, numéro et lien de suivi,
 * courriel au client) et action « Marquer livrée », ouvertes depuis la liste ou la fiche des commandes. Administrateurs du site.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderShipmentAdminController extends AbstractController
{
    private const CSRF_SHIP = 'admin_order_ship';
    private const CSRF_DELIVER = 'admin_order_deliver';

    public function __construct(private readonly AdminOrderShipmentUseCase $shipments, private readonly OrderShipmentService $rules, private readonly AdminUrlGenerator $adminUrls)
    {
    }

    #[Route('/admin/orders/{id}/expedier', name: 'admin_order_ship', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function ship(int $id, Request $request): Response
    {
        $order = $this->shipments->find($id);
        if ($order === null) {
            throw $this->createNotFoundException('Commande introuvable.');
        }
        $error = null;
        $errors = [];
        $input = ['carrier' => $order->getTrackingCarrier() ?? $order->getCarrier()?->getName() ?? '', 'trackingNumber' => $order->getTrackingNumber() ?? '', 'trackingUrl' => '', 'notify' => true];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_SHIP, (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide : rechargez la page.';
            } else {
                $input = ['carrier' => (string) $request->request->get('carrier', ''), 'trackingNumber' => (string) $request->request->get('trackingNumber', ''), 'trackingUrl' => (string) $request->request->get('trackingUrl', ''), 'notify' => $request->request->get('notify') === '1'];
                $result = $this->shipments->ship($order, $input, $request->getSchemeAndHttpHost());
                if ($result['status'] === 200) {
                    $this->addFlash('success', sprintf('Commande %s marquée expédiée%s. %s', $order->getReference(), $input['trackingNumber'] !== '' ? ' (suivi ' . $input['trackingNumber'] . ')' : '', $result['body']['notified'] ? 'Le client a reçu le courriel avec le suivi.' : ($input['notify'] ? 'Attention : le courriel au client n\'a pas pu être envoyé.' : 'Aucun courriel envoyé au client.')));

                    return $this->redirect($this->orderUrl($id));
                }
                $this->addFlash('danger', $result['body']['message'] . (isset($result['body']['errors']) ? ' (' . implode(' ; ', array_map(fn ($e) => $e['path'] . ' : ' . $e['message'], $result['body']['errors'])) . ')' : ''));

                return $this->redirect($this->selfUrl($id));
            }
            if ($error !== null) {
                $this->addFlash('danger', $error);

                return $this->redirect($this->selfUrl($id));
            }
        }
        $blocker = $this->rules->shipBlocker($order);
        $previewUrl = $input['trackingNumber'] !== '' && $input['carrier'] !== '' ? OrderPresenter::trackingUrl($input['carrier'], $input['trackingNumber']) : null;

        return $this->render('admin/orders/ship.html.twig', [
            'order' => $order, 'input' => $input, 'error' => $error, 'errors' => $errors,
            'blockerMessage' => $blocker !== null ? OrderShipmentService::MESSAGES[$blocker] : null,
            'previewUrl' => $previewUrl, 'csrfToken' => self::CSRF_SHIP, 'backUrl' => $this->orderUrl($id),
            'deliverable' => $this->rules->deliverBlocker($order) === null, 'deliverCsrfToken' => self::CSRF_DELIVER,
        ]);
    }

    #[Route('/admin/orders/{id}/livree', name: 'admin_order_deliver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deliver(int $id, Request $request): Response
    {
        $order = $this->shipments->find($id);
        if ($order === null) {
            throw $this->createNotFoundException('Commande introuvable.');
        }
        if (!$this->isCsrfTokenValid(self::CSRF_DELIVER, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');
        } else {
            $result = $this->shipments->deliver($order);
            $this->addFlash($result['status'] === 200 ? 'success' : 'danger', $result['status'] === 200 ? sprintf('Commande %s marquée livrée.', $order->getReference()) : $result['body']['message']);
        }

        return $this->redirect($this->orderUrl($id));
    }

    /** URL habillée EasyAdmin de la page d'expédition (sinon page blanche après redirection) */
    private function selfUrl(int $id): string
    {
        try {
            return $this->adminUrls->unsetAll()->setRoute('admin_order_ship', ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_order_ship', ['id' => $id]);
        }
    }

    private function orderUrl(int $id): string
    {
        return $this->adminUrls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();
    }
}
