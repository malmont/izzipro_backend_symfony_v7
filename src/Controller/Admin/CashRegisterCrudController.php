<?php

namespace App\Controller\Admin;

use App\Entity\CashRegister;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Postes de caisse du site (15/10/2026) : nom unique, actif ou non ; jamais supprimés (historique des sessions) */
class CashRegisterCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CashRegister::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Poste de caisse')
            ->setEntityLabelInPlural('Postes de caisse')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE); // un poste se désactive, il n'est pas supprimé
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom')->setMaxLength(100),
            BooleanField::new('isActive', 'Actif')->renderAsSwitch(false),
            DateTimeField::new('createdAt', 'Créé le')->hideOnForm(),
            // Terminal de paiement Stripe (C4, 23/10/2026) : lecteur du poste sur le compte connecté du site
            \EasyCorp\Bundle\EasyAdminBundle\Field\FormField::addPanel('Terminal de paiement Stripe')->onlyOnForms()
                ->setHelp('Le POS demande le paiement au serveur, qui l\'envoie au lecteur par Internet : la tablette ne voit jamais la carte. Un lecteur réel s\'appaire par le code affiché sur son écran (menu Réglages) ; avec une clé Stripe de test, un lecteur simulé sert aux essais.'),
            TextField::new('stripeReaderLabel', 'Lecteur rattaché')->hideOnForm(),
            TextField::new('stripeReaderId', 'Identifiant du lecteur')->onlyOnDetail(),
            BooleanField::new('stripeReaderSimulated', 'Lecteur simulé')->renderAsSwitch(false)->hideOnForm(),
            BooleanField::new('terminalEnabled', 'Terminal activé')->renderAsSwitch(false)
                ->setHelp('Actif seulement avec un lecteur rattaché ; désactivé, le POS garde la carte déclarée.'),
            TextField::new('pairingCode', 'Appairer un lecteur réel : code d\'appairage')->onlyOnForms()->setRequired(false)
                ->setHelp('Trois mots séparés par des tirets, affichés sur le lecteur. Laisser vide pour ne rien changer.'),
            BooleanField::new('createSimulatedReader', 'Créer un lecteur simulé (clé de test seulement)')->onlyOnForms()->renderAsSwitch(false),
            BooleanField::new('detachReader', 'Détacher le lecteur de ce poste')->onlyOnForms()->renderAsSwitch(false),
        ];
    }

    /** Appairage, lecteur simulé ou détachement demandés dans le formulaire ; une erreur Stripe n'empêche pas l'enregistrement du poste */
    private function applyTerminalChanges(CashRegister $register): void
    {
        $service = $this->container->get(\App\Services\PosTerminalService\PosTerminalPaymentService::class);
        try {
            if ($register->detachReader) {
                $service->detachReader($register);
                $this->addFlash('success', 'Lecteur détaché du poste.');
            } elseif (is_string($register->pairingCode) && trim($register->pairingCode) !== '') {
                $service->pairReader($register, trim($register->pairingCode));
                $this->addFlash('success', sprintf('Lecteur « %s » appairé et terminal activé.', $register->getStripeReaderLabel()));
            } elseif ($register->createSimulatedReader) {
                $service->createSimulatedReader($register);
                $this->addFlash('success', sprintf('Lecteur simulé « %s » rattaché et terminal activé.', $register->getStripeReaderLabel()));
            }
        } catch (\App\Services\CaisseService\CashRegisterException $e) {
            $this->addFlash('danger', 'Terminal : ' . $e->getMessage());
        }
        if ($register->isTerminalEnabled() && $register->getStripeReaderId() === null) {
            $register->setTerminalEnabled(false);
            $this->addFlash('warning', 'Terminal non activé : aucun lecteur rattaché à ce poste.');
        }
        $register->pairingCode = null;
        $register->createSimulatedReader = $register->detachReader = false;
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->applyTerminalChanges($entityInstance);
        $entityManager->flush();
        $this->container->get(\App\Services\PosAuditService\PosAuditService::class)->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_CREATED,
            ['name' => $entityInstance->getName(), 'via' => 'easyadmin'], null, $entityInstance, 'cash_register', $entityInstance->getId());
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->applyTerminalChanges($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        $this->container->get(\App\Services\PosAuditService\PosAuditService::class)->record(\App\Services\PosAuditService\PosAuditService::CASH_REGISTER_UPDATED,
            ['after' => ['name' => $entityInstance->getName(), 'isActive' => $entityInstance->isActive(), 'terminalEnabled' => $entityInstance->isTerminalEnabled(), 'readerId' => $entityInstance->getStripeReaderId()], 'via' => 'easyadmin'], null, $entityInstance, 'cash_register', $entityInstance->getId());
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [\App\Services\PosAuditService\PosAuditService::class => \App\Services\PosAuditService\PosAuditService::class,
            \App\Services\PosTerminalService\PosTerminalPaymentService::class => \App\Services\PosTerminalService\PosTerminalPaymentService::class];
    }
}
