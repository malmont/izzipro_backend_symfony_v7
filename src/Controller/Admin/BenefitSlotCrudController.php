<?php

namespace App\Controller\Admin;

use App\Abo\Entity\BenefitSlot;
use App\Abo\Services\AboAgendaService;
use App\Abo\Services\AboException;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Créneaux datés des prestations (lot ABO-2, 20/11/2026) : liste, créneau ponctuel, ANNULATION (motif dans la fiche puis
 * « Enregistrer » : les droits réservés redeviennent à planifier, chaque abonné reçoit un courriel). Un créneau ne se supprime pas : il
 * s'annule (les réservations restent tracées).
 */
class BenefitSlotCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly AboAgendaService $agenda)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return BenefitSlot::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Créneau')->setEntityLabelInPlural('Créneaux')
            ->setDefaultSort(['startsAt' => 'ASC'])
            ->setHelp('index', 'Les créneaux datés : ceux des règles (8 semaines devant) et les créneaux ponctuels. Pour annuler un créneau, ouvrez-le, écrivez le motif et enregistrez : les abonnés inscrits sont prévenus par courriel et leur séance redevient à planifier, sans perte.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        $creating = $pageName === Crud::PAGE_NEW;
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('benefit', 'Prestation')->setFormTypeOption('disabled', !$creating);
        yield DateTimeField::new('startsAt', 'Début')->setFormTypeOption('disabled', !$creating);
        yield DateTimeField::new('endsAt', 'Fin')->setFormTypeOption('disabled', !$creating);
        yield IntegerField::new('capacity', 'Places')->setFormTypeOption('disabled', !$creating);
        yield TextField::new('location', 'Lieu')->hideOnIndex()->setFormTypeOption('disabled', !$creating);
        yield IntegerField::new('staffUserId', 'Intervenant (n° de compte)')->hideOnIndex()->setFormTypeOption('disabled', !$creating);
        yield ChoiceField::new('status', 'État')->setChoices(['Ouvert' => BenefitSlot::STATUS_OPEN, 'Annulé' => BenefitSlot::STATUS_CANCELLED])->hideOnForm();
        yield AssociationField::new('rule', 'Règle')->hideOnForm()->hideOnIndex();
        if (!$creating) {
            yield TextField::new('cancelReason', 'Motif d\'annulation')->setHelp('Rempli puis enregistré : le créneau est annulé et les abonnés prévenus. Envoyé aux abonnés.');
        }
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $this->agenda->createSlot($entityInstance);
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // seule écriture d'un créneau existant : son annulation, par le service (réservations rendues, courriels, journal)
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $reason = $entityInstance->getCancelReason();
        $entityManager->refresh($entityInstance);
        if (($original['status'] ?? null) !== BenefitSlot::STATUS_OPEN || $reason === null || $reason === '' || $reason === ($original['cancelReason'] ?? null)) {
            return;
        }
        try {
            $r = $this->agenda->cancelSlot((int) $entityInstance->getId(), $reason, $this->getUser()?->getUserIdentifier(), (string) $this->getContext()?->getRequest()->getHost());
            $this->addFlash('success', sprintf('Créneau annulé ; %d réservation(s) rendue(s), abonnés prévenus.', $r['released']));
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
    }
}
