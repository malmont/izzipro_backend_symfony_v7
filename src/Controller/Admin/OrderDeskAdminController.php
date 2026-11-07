<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Services\OrderService\OrderDeskService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche commande refondue (`/admin/commandes/{id}`) et vue « À traiter » (`/admin/commandes/a-traiter`), lot 4 (07/11/2026) : une
 * seule page par commande avec la chronologie, les articles, les paiements, l'expédition, le journal et les actions permises
 * (lots 1 à 3). Administrateurs du site ; habillage EasyAdmin ; toutes les actions restent sur leurs pages et formulaires CSRF.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderDeskAdminController extends AbstractController
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderDeskService $desk, private readonly AdminUrlGenerator $urls, private readonly ?\App\Services\MediaUrlResolver $media = null)
    {
    }

    #[Route('/admin/commandes/a-traiter', name: 'admin_orders_todo', methods: ['GET'])]
    public function todo(): Response
    {
        $todo = $this->desk->todo();
        $urls = [];
        foreach (['toShip', 'toCapture', 'incomplete', 'inTransit'] as $key) {
            foreach ($todo[$key] as $row) {
                $urls[$row['id']] = $this->sheetUrl($row['id']);
            }
        }

        return $this->render('admin/orders/todo.html.twig', ['todo' => $todo, 'sheetUrls' => $urls, 'listUrl' => $this->listUrl()]);
    }

    #[Route('/admin/commandes/{id}', name: 'admin_order_sheet', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sheet(int $id, Request $request): Response
    {
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }
        $host = $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $sheet = $this->desk->sheet($order, $host, 'fr');
        $route = fn (string $name) => $this->urls->unsetAll()->setRoute($name, ['id' => $id])->generateUrl();
        $links = [
            'edit' => $this->urls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::EDIT)->setEntityId($id)->generateUrl(),
            'list' => $this->listUrl(), 'todo' => $this->urls->unsetAll()->setRoute('admin_orders_todo')->generateUrl(),
            'ship' => $route('admin_order_ship'), 'label' => $route('admin_order_label'), 'refund' => $route('admin_order_refund'), 'cancel' => $route('admin_order_cancel'), 'emails' => $route('admin_order_emails'),
            'customer' => $sheet['customer'] ? $this->urls->unsetAll()->setController(UserCrudController::class)->setAction(Action::DETAIL)->setEntityId($sheet['customer']['id'])->generateUrl() : null,
        ];

        return $this->render('admin/orders/sheet.html.twig', ['sheet' => $sheet, 'links' => $links, 'orderId' => $id]);
    }

    private function sheetUrl(int $id): string
    {
        return $this->urls->unsetAll()->setRoute('admin_order_sheet', ['id' => $id])->generateUrl();
    }

    private function listUrl(): string
    {
        return $this->urls->unsetAll()->setController(OrderCrudController::class)->setAction(Action::INDEX)->generateUrl();
    }
}
