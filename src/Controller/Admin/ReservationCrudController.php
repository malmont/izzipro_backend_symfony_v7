<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Controller\Admin\BaseTenantCrudController;
use App\Services\TenantEntityManagerProvider;
use App\Services\ReservationService\ReservationMailerService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

class ReservationCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    private ?string $previousStatus = null;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private ReservationMailerService $mailerService,
        private AdminUrlGenerator $adminUrlGenerator,
        private ?\App\Services\ReservationService\ShopBookingService $bookings = null, // lot CMD-WEB-1 : le geste a un seul code
    ) {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $confirmAction = Action::new('confirmReservation', 'Confirmer', 'fa fa-check-circle')
            ->linkToUrl(fn (Reservation $reservation) => $this->csrfActionUrl('confirmReservation', $reservation->getId()))
            ->setCssClass('text-success')
            ->displayIf(fn (Reservation $reservation) => $reservation->getStatus() !== 'confirmed');

        $cancelAction = Action::new('cancelReservation', 'Annuler', 'fa fa-times-circle')
            ->linkToUrl(fn (Reservation $reservation) => $this->csrfActionUrl('cancelReservation', $reservation->getId()))
            ->setCssClass('text-danger')
            ->displayIf(fn (Reservation $reservation) => $reservation->getStatus() !== 'cancelled');

        return $actions
            ->add(Crud::PAGE_INDEX, $confirmAction)
            ->add(Crud::PAGE_INDEX, $cancelAction)
            ->add(Crud::PAGE_DETAIL, $confirmAction)
            ->add(Crud::PAGE_DETAIL, $cancelAction);
    }

    public function confirmReservation(AdminContext $context): Response
    {
        if (!$this->isCsrfActionValid($context, 'confirmReservation')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');
            return $this->redirect($this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction('index')->generateUrl());
        }

        /** @var Reservation|null $reservation */
        $reservation = $context->getEntity()->getInstance();
        if (!$reservation) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }

        if (!$this->changeStatus($reservation, 'confirmed')) {
            $this->addFlash('info', sprintf('Réservation #%d déjà confirmée : aucun courriel renvoyé.', $reservation->getId()));

            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }
        $this->addFlash('success', sprintf('✅ Réservation #%d de %s confirmée ! Un email a été envoyé à %s.', $reservation->getId(), $reservation->getClientName(), $reservation->getClientEmail()));

        return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
    }

    public function cancelReservation(AdminContext $context): Response
    {
        if (!$this->isCsrfActionValid($context, 'cancelReservation')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');
            return $this->redirect($this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction('index')->generateUrl());
        }

        /** @var Reservation|null $reservation */
        $reservation = $context->getEntity()->getInstance();
        if (!$reservation) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }

        if (!$this->changeStatus($reservation, 'cancelled')) {
            $this->addFlash('info', sprintf('Réservation #%d déjà annulée : aucun courriel renvoyé.', $reservation->getId()));

            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }
        $this->addFlash('info', sprintf('ℹ️ Réservation #%d de %s annulée. Un email a été envoyé à %s.', $reservation->getId(), $reservation->getClientName(), $reservation->getClientEmail()));

        return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
    }

    /**
     * Lot CMD-WEB-1 : confirmer ou annuler passe par le service partagé avec la gestion web et l'assistant (ligne verrouillée, journal,
     * courriel une seule fois) ; comme avant : toutes les lignes de l'écran (entretiens de Mémoires Vivantes compris), et un rendez-vous
     * annulé peut être reconfirmé.
     */
    /** @return bool l'état a changé (un courriel est parti) */
    private function changeStatus(Reservation $reservation, string $status): bool
    {
        if ($this->bookings === null) {
            $reservation->setStatus($status);
            $this->emProvider->getEntityManager()->flush();
            $this->mailerService->sendStatusChangeEmail($reservation, $status);

            return true;
        }

        return $this->bookings->changeStatus((int) $reservation->getId(), $status, false, (string) $this->getUser()?->getUserIdentifier(), 'fr', null, true)['changed'];
    }

    public function createEditForm(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormInterface
    {
        /** @var Reservation $entity */
        $entity = $entityDto->getInstance();
        $this->previousStatus = $entity->getStatus();

        return parent::createEditForm($entityDto, $formOptions, $context);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Reservation) {
            $tenantEm = $this->emProvider->getEntityManager();
            $uow = $tenantEm->getUnitOfWork();
            $originalData = $uow->getOriginalEntityData($entityInstance);
            $oldStatus = $originalData['status'] ?? null;
            $newStatus = $entityInstance->getStatus();

            parent::updateEntity($entityManager, $entityInstance);

            // Si le statut a changé ou a été passé à confirmed/cancelled
            if ($oldStatus !== $newStatus && in_array($newStatus, ['confirmed', 'cancelled'], true)) {
                $this->mailerService->sendStatusChangeEmail($entityInstance, $newStatus);
            }
            return;
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('clientName', 'Nom du Client');
        yield EmailField::new('clientEmail', 'Email');
        yield TextField::new('clientPhone', 'Téléphone');
        yield TextField::new('serviceName', 'Prestation');
        yield TextField::new('stepLabel', 'Séance / Étape')
            ->onlyOnIndex()
            ->formatValue(fn ($value, Reservation $entity) => $entity->getStepLabel() ?: '—');
        yield DateField::new('reservationDate', 'Date souhaitée');
        yield TextField::new('reservationSlot', 'Créneau horaire');
        yield AssociationField::new('biographer', 'Biographe');
        yield IntegerField::new('numberOfGuests', 'Personnes / Logements');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'En attente' => 'pending',
                'Confirmé' => 'confirmed',
                'Annulé' => 'cancelled',
            ])
            ->renderAsBadges([
                'pending' => 'warning',
                'confirmed' => 'success',
                'cancelled' => 'danger',
            ]);
        yield TextField::new('forfaitName', 'Nom du Forfait')->hideOnIndex();
        yield TextField::new('bookId', 'ID Livre')->hideOnIndex();
        yield IntegerField::new('stepNumber', 'Étape N°')->hideOnIndex();
        yield IntegerField::new('totalSteps', 'Total d\'étapes')->hideOnIndex();
        yield TextField::new('quickActions', 'Action rapide')
            ->onlyOnIndex()
            ->setTemplatePath('admin/fields/reservation_quick_actions.html.twig');
        yield TextareaField::new('notes', 'Notes / Demandes')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}
