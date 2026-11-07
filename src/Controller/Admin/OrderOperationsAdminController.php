<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\OrderService\OrderOperationsService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Opérations de support sur une commande web depuis le back-office (lot 3, 07/11/2026) : rembourser (total ou partiel, motif),
 * capturer un paiement autorisé, annuler avec motif, renvoyer un courriel (confirmation, reçu, expédition). Une page par opération,
 * formulaire CSRF, redirection habillée EasyAdmin, résultat en message. Administrateurs du site.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderOperationsAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_order_ops';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderOperationsService $ops, private readonly TenantCurrencyProvider $currency, private readonly AdminUrlGenerator $adminUrls)
    {
    }

    #[Route('/admin/orders/{id}/rembourser', name: 'admin_order_refund', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function refund(int $id, Request $request): Response
    {
        $order = $this->order($id);
        if ($request->isMethod('POST')) {
            if (!$this->csrf($request)) {
                return $this->redirect($this->selfUrl('admin_order_refund', $id));
            }
            $amount = (int) round((float) str_replace([',', ' '], ['.', ''], (string) $request->request->get('amount', '0')) * 100);
            $result = $this->ops->refund($order, $amount, (string) $request->request->get('reason', ''), $request->request->get('notify') === '1', $this->getUser()?->getUserIdentifier(), $request->getSchemeAndHttpHost());
            if ($result['status'] === 200) {
                $b = $result['body'];
                $this->addFlash('success', sprintf('%s %s remboursé(s) sur la commande %s%s. %s', number_format($b['amountCents'] / 100, 2, ',', ' '), $this->currency->code(), $order->getReference(), $b['partial'] ? ' (une part a été refusée par Stripe : le reste est à rembourser depuis Stripe)' : '', $b['notified'] ? 'Le client a reçu le courriel.' : 'Aucun courriel envoyé.'));

                return $this->redirect($this->orderUrl($id));
            }
            $this->addFlash('danger', $result['body']['message']);

            return $this->redirect($this->selfUrl('admin_order_refund', $id));
        }
        $money = $this->ops->money($order);

        return $this->render('admin/orders/operations.html.twig', ['page' => 'refund', 'order' => $order, 'money' => $money, 'currency' => $this->currency->code(), 'csrfToken' => self::CSRF_ID, 'backUrl' => $this->orderUrl($id),
            'blocker' => $money['counterSale'] ? OrderOperationsService::MESSAGES['COUNTER_SALE'] : ((int) ($order->getStatus()?->getId() ?? 0) === 7 ? OrderOperationsService::MESSAGES['ORDER_CANCELLED'] : ($money['refundableCents'] <= 0 ? OrderOperationsService::MESSAGES['NO_ONLINE_PAYMENT'] : null))]);
    }

    #[Route('/admin/orders/{id}/annuler', name: 'admin_order_cancel', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $order = $this->order($id);
        if ($request->isMethod('POST')) {
            if (!$this->csrf($request)) {
                return $this->redirect($this->selfUrl('admin_order_cancel', $id));
            }
            if ($request->request->get('confirm') !== '1') {
                $this->addFlash('danger', 'Cochez la case pour confirmer l\'annulation.');

                return $this->redirect($this->selfUrl('admin_order_cancel', $id));
            }
            $result = $this->ops->cancel($order, (string) $request->request->get('reason', ''), $request->request->get('notify') === '1', $this->getUser()?->getUserIdentifier(), $request->getSchemeAndHttpHost());
            if ($result['status'] === 200) {
                $b = $result['body'];
                $this->addFlash('success', sprintf('Commande %s annulée%s. %s', $order->getReference(), $b['refundedCents'] > 0 ? sprintf(', %s %s remboursés chez Stripe', number_format($b['refundedCents'] / 100, 2, ',', ' '), $this->currency->code()) : '', $b['notified'] ? 'Le client a reçu le courriel.' : 'Aucun courriel envoyé.'));

                return $this->redirect($this->orderUrl($id));
            }
            $this->addFlash('danger', $result['body']['message']);

            return $this->redirect($this->selfUrl('admin_order_cancel', $id));
        }
        $money = $this->ops->money($order);
        $status = (int) ($order->getStatus()?->getId() ?? 0);

        return $this->render('admin/orders/operations.html.twig', ['page' => 'cancel', 'order' => $order, 'money' => $money, 'currency' => $this->currency->code(), 'csrfToken' => self::CSRF_ID, 'backUrl' => $this->orderUrl($id),
            'blocker' => $money['counterSale'] ? OrderOperationsService::MESSAGES['COUNTER_SALE'] : ($status === 7 ? OrderOperationsService::MESSAGES['ORDER_CANCELLED'] : (in_array($status, [5, 6], true) ? OrderOperationsService::MESSAGES['ALREADY_SHIPPED'] : null))]);
    }

    #[Route('/admin/orders/{id}/courriels', name: 'admin_order_emails', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function emails(int $id, Request $request): Response
    {
        $order = $this->order($id);
        if ($request->isMethod('POST')) {
            if (!$this->csrf($request)) {
                return $this->redirect($this->selfUrl('admin_order_emails', $id));
            }
            $result = $this->ops->resend($order, (string) $request->request->get('kind', ''), $request->getSchemeAndHttpHost());
            $labels = ['confirmation' => 'confirmation de commande', 'receipt' => 'reçu', 'shipped' => 'avis d\'expédition'];
            if ($result['status'] === 200 && $result['body']['sent']) {
                $this->addFlash('success', sprintf('Courriel « %s » renvoyé à %s.', $labels[$result['body']['kind']] ?? $result['body']['kind'], $order->getUserId()?->getEmail()));
            } else {
                $this->addFlash('danger', $result['status'] === 200 ? 'Le courriel n\'a pas pu être envoyé (voir le journal).' : $result['body']['message']);
            }

            return $this->redirect($this->selfUrl('admin_order_emails', $id));
        }

        return $this->render('admin/orders/operations.html.twig', ['page' => 'emails', 'order' => $order, 'money' => $this->ops->money($order), 'currency' => $this->currency->code(), 'csrfToken' => self::CSRF_ID, 'backUrl' => $this->orderUrl($id), 'blocker' => null]);
    }

    #[Route('/admin/orders/{id}/capturer', name: 'admin_order_capture', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function capture(int $id, Request $request): Response
    {
        $order = $this->order($id);
        if ($this->csrf($request)) {
            $result = $this->ops->capture($order, $this->getUser()?->getUserIdentifier());
            $this->addFlash($result['status'] === 200 ? 'success' : 'danger', $result['status'] === 200 ? sprintf('Paiement capturé : %s %s. La commande %s est payée.', number_format($result['body']['amountCents'] / 100, 2, ',', ' '), $this->currency->code(), $order->getReference()) : $result['body']['message']);
        }

        return $this->redirect($this->orderUrl($id));
    }

    private function order(int $id): Order
    {
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $order;
    }

    private function csrf(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

        return false;
    }

    private function orderUrl(int $id): string
    {
        return $this->adminUrls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();
    }

    private function selfUrl(string $route, int $id): string
    {
        try {
            return $this->adminUrls->unsetAll()->setRoute($route, ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route, ['id' => $id]);
        }
    }
}
