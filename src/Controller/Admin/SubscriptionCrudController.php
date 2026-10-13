<?php

namespace App\Controller\Admin;

use App\Entity\Subscription;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\SubscriptionUseCase\AdminSubscriptionUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abonnements des clients : l'état vient de Stripe (webhooks). Depuis le 13/10/2026, l'administrateur du site agit
 * pour le client depuis la fiche (AdminSubscriptionUseCase) : résiliation en fin de période ou immédiate, reprise,
 * pause, changement de formule, relecture chez Stripe, remboursement du dernier paiement. Pas de création, de
 * modification directe ni de suppression : l'état resterait sinon différent de celui de Stripe.
 */
class SubscriptionCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    private const RUNNING = [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_PAUSED];

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly AdminSubscriptionUseCase $admin)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Subscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Abonnement')->setEntityLabelInPlural('Abonnements')->setDefaultSort(['id' => 'DESC'])
            ->setHelp('index', 'L\'état est recopié depuis Stripe. Ouvrez un abonnement pour agir pour le client : résilier, reprendre, mettre en pause, changer de formule, rembourser le dernier paiement. Le client reçoit les mêmes courriels que s\'il avait agi lui-même.')
            ->setHelp('detail', 'Résilier en fin de période : le client garde l\'accès jusqu\'à la fin de la période payée. Résilier maintenant : immédiat, sans remboursement (rembourser à part si besoin). Rembourser : rend en entier le dernier paiement encaissé, sans toucher à l\'abonnement.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $confirm = fn (string $question) => ['onclick' => 'return confirm(' . json_encode($question, JSON_UNESCAPED_UNICODE) . ');'];
        $stripe = fn (Subscription $s) => $s->getStripeSubscriptionId() !== null;
        $new = fn (string $name, string $label, string $icon) => Action::new($name, $label, $icon)
            ->linkToUrl(fn (Subscription $s) => $this->csrfActionUrl($name, (string) $s->getId()));
        $list = [
            $new('cancelAtPeriodEnd', 'Résilier en fin de période', 'fas fa-calendar-times')
                ->displayIf(fn (Subscription $s) => $stripe($s) && in_array($s->getStatus(), self::RUNNING, true) && !$s->isCancelAtPeriodEnd())
                ->setHtmlAttributes($confirm('Résilier cet abonnement à la fin de la période en cours ? Le client sera prévenu par courriel.')),
            $new('resumeSubscription', 'Reprendre', 'fas fa-play')
                ->displayIf(fn (Subscription $s) => $stripe($s) && $s->getStatus() !== Subscription::STATUS_CANCELED && ($s->isCancelAtPeriodEnd() || $s->getStatus() === Subscription::STATUS_PAUSED))
                ->addCssClass('text-success'),
            $new('pauseSubscription', 'Mettre en pause', 'fas fa-pause')
                ->displayIf(fn (Subscription $s) => $stripe($s) && in_array($s->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true))
                ->setHtmlAttributes($confirm('Mettre en pause ? Aucune échéance ne sera facturée jusqu\'à la reprise. Le client sera prévenu par courriel.')),
            $new('changePlan', 'Changer de formule', 'fas fa-exchange-alt')
                ->displayIf(fn (Subscription $s) => $stripe($s) && $s->getStatus() !== Subscription::STATUS_CANCELED),
            $new('syncSubscription', 'Relire chez Stripe', 'fas fa-sync')
                ->displayIf($stripe),
            $new('refundLatest', 'Rembourser le dernier paiement', 'fas fa-undo')
                ->displayIf($stripe)->addCssClass('text-warning')
                ->setHtmlAttributes($confirm('Rembourser en entier le dernier paiement encaissé de cet abonnement ? L\'abonnement lui-même ne change pas.')),
            $new('cancelNow', 'Résilier maintenant', 'fas fa-ban')
                ->displayIf(fn (Subscription $s) => $stripe($s) && $s->getStatus() !== Subscription::STATUS_CANCELED)->addCssClass('text-danger')
                ->setHtmlAttributes($confirm('Résilier immédiatement, sans remboursement ? Le client sera prévenu par courriel.')),
        ];
        $actions->disable(Action::NEW, Action::EDIT, Action::DELETE)->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ($list as $action) {
            $actions->add(Crud::PAGE_DETAIL, $action);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('user', 'Client');
        yield AssociationField::new('plan', 'Formule');
        yield IntegerField::new('quantity', 'Quantité');
        yield TextField::new('status', 'Statut');
        yield DateTimeField::new('currentPeriodEnd', 'Fin de période');
        yield BooleanField::new('cancelAtPeriodEnd', 'Fin programmée')->renderAsSwitch(false);
        yield DateTimeField::new('pausedAt', 'En pause depuis')->onlyOnDetail();
        yield AssociationField::new('address', 'Adresse')->onlyOnDetail();
        yield AssociationField::new('carrier', 'Transporteur')->onlyOnDetail();
        yield TextField::new('stripeSubscriptionId', 'Abonnement Stripe')->onlyOnDetail();
        yield TextField::new('stripeCustomerId', 'Client Stripe')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Créé le');
        yield DateTimeField::new('canceledAt', 'Résilié le')->onlyOnDetail();
    }

    public function cancelAtPeriodEnd(AdminContext $context): Response
    {
        return $this->act($context, 'cancelAtPeriodEnd', fn (Subscription $s) => $this->admin->cancel($s, true, $context->getRequest()->getSchemeAndHttpHost()), 'Résiliation programmée à la fin de la période. Le client a été prévenu.');
    }

    public function cancelNow(AdminContext $context): Response
    {
        return $this->act($context, 'cancelNow', fn (Subscription $s) => $this->admin->cancel($s, false, $context->getRequest()->getSchemeAndHttpHost()), 'Abonnement résilié. Le client a été prévenu.');
    }

    public function resumeSubscription(AdminContext $context): Response
    {
        return $this->act($context, 'resumeSubscription', fn (Subscription $s) => $this->admin->resume($s), 'Abonnement repris.');
    }

    public function pauseSubscription(AdminContext $context): Response
    {
        return $this->act($context, 'pauseSubscription', fn (Subscription $s) => $this->admin->pause($s, $context->getRequest()->getSchemeAndHttpHost()), 'Abonnement en pause. Le client a été prévenu.');
    }

    public function syncSubscription(AdminContext $context): Response
    {
        return $this->act($context, 'syncSubscription', fn (Subscription $s) => $this->admin->sync($s), 'État relu chez Stripe.');
    }

    public function refundLatest(AdminContext $context): Response
    {
        $message = '';

        return $this->act($context, 'refundLatest', function (Subscription $s) use (&$message): void {
            $refund = $this->admin->refundLatest($s);
            $message = sprintf('Remboursement effectué : %s %s (Stripe %s).', number_format($refund['amount'] / 100, 2, ',', ' '), strtoupper($refund['currency']), $refund['refundId']);
        }, function () use (&$message): string { return $message; }); // par référence : rempli pendant l'action
    }

    /** Page de choix de la nouvelle formule (GET), puis changement (POST, champ planId) */
    public function changePlan(AdminContext $context): Response
    {
        $subscription = $context->getEntity()->getInstance();
        if (!$subscription instanceof Subscription || !$this->isCsrfActionValid($context, 'changePlan')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la fiche.');

            return $this->redirect($this->url(Action::INDEX));
        }
        if ($context->getRequest()->isMethod('POST')) {
            return $this->act($context, 'changePlan', fn (Subscription $s) => $this->admin->changePlan($s, (int) $context->getRequest()->request->get('planId')), 'Formule changée (prorata calculé par Stripe sur la prochaine facture).');
        }

        return $this->render('admin/subscription/change_plan.html.twig', [
            'subscription' => $subscription,
            'plans' => $this->admin->otherPlans($subscription),
            'action' => $this->csrfActionUrl('changePlan', (string) $subscription->getId()),
            'back' => $this->url(Action::DETAIL, $subscription->getId()),
        ]);
    }

    /** @param string|callable(): string $message */
    private function act(AdminContext $context, string $action, callable $apply, string|callable $message): Response
    {
        $subscription = $context->getEntity()->getInstance();
        if (!$subscription instanceof Subscription || !$this->isCsrfActionValid($context, $action)) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la fiche.');

            return $this->redirect($this->url(Action::INDEX));
        }
        try {
            $apply($subscription);
            $this->addFlash('success', is_callable($message) ? $message() : $message);
        } catch (SubscriptionException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->url(Action::DETAIL, $subscription->getId()));
    }

    private function url(string $action, ?int $id = null): string
    {
        $url = $this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction($action);

        return ($id !== null ? $url->setEntityId($id) : $url)->generateUrl();
    }
}
