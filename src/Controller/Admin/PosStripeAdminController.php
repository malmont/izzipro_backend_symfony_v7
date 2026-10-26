<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosStripeService\PosHealthService;
use App\Services\PosStripeService\PosStripeModeService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paiements de caisse (Stripe) dans EasyAdmin (M1) : indicateur « Paiements de caisse : test | réel », compte Connect de chaque mode
 * (pièces manquantes, encaissement, virements), inscription Stripe du compte réel, emplacement Terminal, passage test ↔ réel (contrôlé,
 * journalisé) et bloc de surveillance (même rapport que GET /api/pos/health). La boutique en ligne garde sa page « Stripe » actuelle.
 */
#[IsGranted('ROLE_ADMIN')]
class PosStripeAdminController extends AbstractController
{
    private const CSRF = 'pos_stripe_admin';

    public function __construct(private readonly PosStripeModeService $modes, private readonly PosHealthService $health, private readonly PosTerminalPaymentService $terminal)
    {
    }

    #[Route('/admin/pos/stripe', name: 'admin_pos_stripe', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;
        $done = null;
        /** @var User|null $user */
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $error = 'Formulaire expiré : rechargez la page.';
            } else {
                try {
                    switch ((string) $request->request->get('action')) {
                        case 'onboarding':
                            $return = $this->generateUrl('admin_pos_stripe_return', [], UrlGeneratorInterface::ABSOLUTE_URL);

                            return $this->redirect($this->modes->liveOnboardingLink($this->generateUrl('admin_pos_stripe', [], UrlGeneratorInterface::ABSOLUTE_URL), $return, $user));
                        case 'refresh':
                            $this->modes->refreshLiveAccount($user);
                            $done = 'État du compte réel relu chez Stripe.';
                            break;
                        case 'location':
                            $this->terminal->ensureLocation();
                            $done = 'Emplacement Terminal prêt à l\'adresse du magasin.';
                            break;
                        case 'mode_live':
                        case 'mode_test':
                            $result = $this->modes->switchMode($request->request->get('action') === 'mode_live' ? 'live' : 'test', $user);
                            $done = $result['changed'] ? sprintf('Paiements de caisse en mode %s%s.', $result['mode'] === 'live' ? 'RÉEL' : 'test',
                                $result['detachedReaders'] !== [] ? sprintf(' ; %d lecteur(s) détaché(s), à appairer de nouveau (fiche du poste)', count($result['detachedReaders'])) : '') : 'Mode inchangé.';
                            break;
                    }
                } catch (CashRegisterException $e) {
                    $error = $e->getMessage() . (isset($e->context['currentlyDue']) && $e->context['currentlyDue'] ? ' (pièces : ' . implode(', ', $e->context['currentlyDue']) . ')' : '');
                }
            }
        }

        return $this->render('admin/pos_stripe/index.html.twig', ['report' => $this->health->report(true), 'overview' => $this->modes->overview(true), 'error' => $error, 'done' => $done, 'csrf' => self::CSRF]);
    }

    /** Retour de l'inscription Stripe : état du compte réel relu, puis la page */
    #[Route('/admin/pos/stripe/return', name: 'admin_pos_stripe_return', methods: ['GET'])]
    public function return(): Response
    {
        try {
            $this->modes->refreshLiveAccount($this->getUser() instanceof User ? $this->getUser() : null);
        } catch (CashRegisterException) {
            // l'état reste affiché tel que Stripe le donne sur la page
        }

        return $this->redirectToRoute('admin_pos_stripe');
    }
}
