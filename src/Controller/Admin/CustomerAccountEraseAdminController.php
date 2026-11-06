<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\AccountUseCase\AdminEraseCustomerAccountUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page de confirmation « Effacer ce compte client » (B19, 06/11/2026), ouverte depuis la liste ou la fiche des utilisateurs.
 * Montre ce qui sera effacé et ce qui est gardé, les bloqueurs avec un lien vers la fiche à régler (Abonnements, Réservations),
 * puis efface après un formulaire CSRF et une case « irréversible ». Réservée aux administrateurs du site ; un compte interne,
 * déjà effacé ou le sien n'est jamais effaçable (AdminEraseCustomerAccountUseCase).
 */
#[IsGranted('ROLE_ADMIN')]
class CustomerAccountEraseAdminController extends AbstractController
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly AdminEraseCustomerAccountUseCase $erase, private readonly AdminUrlGenerator $adminUrls)
    {
    }

    #[Route('/admin/users/{id}/effacer', name: 'admin_user_erase', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function confirm(int $id, Request $request): Response
    {
        $actor = $this->getUser();
        $target = $this->emProvider->getEntityManager()->getRepository(User::class)->find($id);
        if (!$actor instanceof User || !$target instanceof User) {
            throw $this->createNotFoundException('Compte introuvable.');
        }
        $csrfId = 'admin_user_erase'; // le compte visé est dans l'URL ; le jeton protège contre la requête forgée depuis un autre site
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide : rechargez la page.';
            } elseif ($request->request->get('confirm') !== '1') {
                $error = 'Cochez la case pour confirmer : l\'effacement est irréversible.';
            } else {
                $result = $this->erase->execute($target, $actor, $request);
                if ($result['status'] === 200) {
                    $body = $result['body'];
                    $this->addFlash('success', sprintf(
                        'Compte client n° %d effacé : %d adresse(s), %d avis, %d réservation(s) anonymisés, %d livre(s) Mémoires Vivantes effacé(s)%s%s. %s',
                        $id, $body['addressesAnonymized'], $body['reviewsAnonymized'], $body['reservationsAnonymized'], $body['booksDeleted'],
                        $body['newsletterRemoved'] ? ', infolettre retirée' : '', $body['stripeCustomerDeleted'] ? ', client Stripe supprimé' : '',
                        $body['noticeSent'] ? 'Le client a reçu le courriel de confirmation.' : 'Attention : le courriel de confirmation n\'a pas pu être envoyé (voir le journal).'
                    ));

                    return $this->redirect($this->adminUrls->unsetAll()->setController(UserCrudController::class)->setAction(Action::INDEX)->generateUrl());
                }
                $error = $result['body']['message'];
            }
        }
        $preview = $this->erase->preview($target, $actor);
        $detail = fn (string $controller, int $entityId): string => $this->adminUrls->unsetAll()->setController($controller)->setAction(Action::DETAIL)->setEntityId($entityId)->generateUrl();

        return $this->render('admin/users/erase.html.twig', [
            'target' => $target,
            'preview' => $preview,
            'reasonMessage' => $preview['reason'] !== null ? AdminEraseCustomerAccountUseCase::MESSAGES[$preview['reason']] : null,
            'subscriptionLinks' => array_map(fn (array $s): array => $s + ['url' => $detail(SubscriptionCrudController::class, $s['id'])], $preview['blockers']['subscriptions']),
            'reservationLinks' => array_map(fn (array $r): array => $r + ['url' => $detail(ReservationCrudController::class, $r['id'])], $preview['blockers']['reservations']),
            'backUrl' => $this->adminUrls->unsetAll()->setController(UserCrudController::class)->setAction(Action::INDEX)->generateUrl(),
            'csrfId' => $csrfId,
            'error' => $error,
        ]);
    }
}
