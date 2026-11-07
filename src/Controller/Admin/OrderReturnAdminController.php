<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Services\MediaUrlResolver;
use App\Services\OrderService\OrderAddressService;
use App\Services\OrderService\OrderReturnService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Retours web et changement d'adresse (lot 7, 07/11/2026) : `/admin/orders/{id}/retour` (lignes et quantités rendues, motif, remise en stock,
 * courriel ; remboursement réel chez Stripe) et `/admin/orders/{id}/adresse` (nouvelle adresse de livraison avant expédition).
 * Administrateurs du site ; habillage EasyAdmin ; retour sur la fiche de la commande.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderReturnAdminController extends AbstractController
{
    private const CSRF_RETURN = 'admin_order_return';
    private const CSRF_ADDRESS = 'admin_order_address';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderReturnService $returns, private readonly OrderAddressService $addresses, private readonly AdminUrlGenerator $urls, private readonly ?MediaUrlResolver $media = null)
    {
    }

    #[Route('/admin/orders/{id}/retour', name: 'admin_order_return', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function return(int $id, Request $request): Response
    {
        $order = $this->order($id);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_RETURN, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

                return $this->redirect($this->pageUrl('admin_order_return', $id));
            }
            $lines = [];
            foreach ((array) $request->request->all('quantity') as $itemId => $qty) {
                $lines[] = ['orderItemId' => (int) $itemId, 'quantity' => (int) $qty];
            }
            $domain = $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
            $result = $this->returns->create($order, $lines, (string) $request->request->get('reason', ''), $request->request->get('restock') === '1', $request->request->get('notify') === '1', $this->getUser()?->getUserIdentifier(), $domain);
            if ($result['status'] === 201) {
                $r = $result['body']['return'];
                $this->addFlash('success', sprintf('Retour enregistré sur la commande %s : %s remboursé(s) chez Stripe%s. %s', $order->getReference(), number_format($r['amountCents'] / 100, 2, ',', ' '), $result['body']['restocked'] !== [] ? ', articles remis en stock' : '', $result['body']['notified'] ? 'Le client a reçu le courriel.' : 'Aucun courriel envoyé au client.'));

                return $this->redirect($this->pageUrl('admin_order_sheet', $id));
            }
            $this->addFlash('danger', $result['body']['message']);

            return $this->redirect($this->pageUrl('admin_order_return', $id));
        }
        $blocker = $this->returns->blocker($order);

        return $this->render('admin/orders/retour.html.twig', ['order' => $order, 'lines' => $this->returns->lines($order), 'returns' => array_map(fn ($r) => $r->toArray(), $this->returns->returns($order)),
            'blockerMessage' => $blocker !== null ? OrderReturnService::MESSAGES[$blocker] : null, 'csrfToken' => self::CSRF_RETURN, 'backUrl' => $this->pageUrl('admin_order_sheet', $id), 'currency' => $this->currency()]);
    }

    #[Route('/admin/orders/{id}/adresse', name: 'admin_order_address', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function address(int $id, Request $request): Response
    {
        $order = $this->order($id);
        $values = $this->addresses->current($order);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ADDRESS, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

                return $this->redirect($this->pageUrl('admin_order_address', $id));
            }
            foreach (OrderAddressService::FIELDS as $f) {
                $values[$f] = (string) $request->request->get($f, '');
            }
            $result = $this->addresses->change($order, $values, $this->getUser()?->getUserIdentifier());
            if ($result['status'] === 200) {
                $this->addFlash('success', sprintf('Adresse de livraison de la commande %s remplacée. Frais de livraison et taxes déjà payés inchangés.', $order->getReference()));

                return $this->redirect($this->pageUrl('admin_order_sheet', $id));
            }
            $this->addFlash('danger', $result['body']['message'] . (isset($result['body']['errors']) ? ' (' . implode(' ; ', array_map(fn ($e) => $e['path'] . ' : ' . $e['message'], $result['body']['errors'])) . ')' : ''));
            if ($result['status'] !== 422) {
                return $this->redirect($this->pageUrl('admin_order_sheet', $id));
            }
        }
        $blocker = $this->addresses->blocker($order);

        return $this->render('admin/orders/adresse.html.twig', ['order' => $order, 'values' => $values, 'blockerMessage' => $blocker !== null ? OrderAddressService::MESSAGES[$blocker] : null, 'csrfToken' => self::CSRF_ADDRESS, 'backUrl' => $this->pageUrl('admin_order_sheet', $id)]);
    }

    private function order(int $id): Order
    {
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $order;
    }

    private function currency(): string
    {
        try {
            return (string) ($this->emProvider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([])?->getCurrency() ?: 'CAD');
        } catch (\Throwable) {
            return 'CAD';
        }
    }

    private function pageUrl(string $route, int $id): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route, ['id' => $id]);
        }
    }
}
