<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
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
    private ?string $previousStatus = null;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private ReservationMailerService $mailerService,
        private AdminUrlGenerator $adminUrlGenerator
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
            ->linkToCrudAction('confirmReservation')
            ->setCssClass('text-success')
            ->displayIf(fn (Reservation $reservation) => $reservation->getStatus() !== 'confirmed');

        $cancelAction = Action::new('cancelReservation', 'Annuler', 'fa fa-times-circle')
            ->linkToCrudAction('cancelReservation')
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
        /** @var Reservation|null $reservation */
        $reservation = $context->getEntity()->getInstance();
        if (!$reservation) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }

        $tenantEm = $this->emProvider->getEntityManager();
        $reservation->setStatus('confirmed');
        $tenantEm->flush();

        $this->mailerService->sendStatusChangeEmail($reservation, 'confirmed');
        $this->addFlash('success', sprintf('✅ Réservation #%d de %s confirmée ! Un email a été envoyé à %s.', $reservation->getId(), $reservation->getClientName(), $reservation->getClientEmail()));

        return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
    }

    public function cancelReservation(AdminContext $context): Response
    {
        /** @var Reservation|null $reservation */
        $reservation = $context->getEntity()->getInstance();
        if (!$reservation) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
        }

        $tenantEm = $this->emProvider->getEntityManager();
        $reservation->setStatus('cancelled');
        $tenantEm->flush();

        $this->mailerService->sendStatusChangeEmail($reservation, 'cancelled');
        $this->addFlash('info', sprintf('ℹ️ Réservation #%d de %s annulée. Un email a été envoyé à %s.', $reservation->getId(), $reservation->getClientName(), $reservation->getClientEmail()));

        return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl());
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
        yield DateField::new('reservationDate', 'Date souhaitée');
        yield TextField::new('reservationSlot', 'Créneau horaire');
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
        yield TextField::new('quickActions', 'Action rapide')
            ->onlyOnIndex()
            ->setTemplatePath('admin/fields/reservation_quick_actions.html.twig');
        yield TextareaField::new('notes', 'Notes / Demandes')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}
