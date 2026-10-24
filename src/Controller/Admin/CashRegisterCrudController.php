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
            TextField::new('receiptPrefix', 'Préfixe des numéros de ticket')->setRequired(false)->setMaxLength(10)
                ->setHelp('Présentation seulement (ex. « C1- » donne C1-123) : la suite numérique du poste reste continue, les numéros déjà attribués ne changent pas.'),
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
            // Kit Stripe Terminal dans l'application (F13/F16, 31/10/2026)
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('terminalMode', 'Mode du terminal')->hideOnIndex()->setRequired(false)
                ->setChoices(['Manuel (carte déclarée)' => CashRegister::MODE_MANUAL, 'Piloté par le serveur (WisePOS E, S700)' => CashRegister::MODE_SERVER, 'Tap to Pay (le téléphone ou la tablette lit la carte)' => CashRegister::MODE_TAP_TO_PAY, 'Lecteur Bluetooth (WisePad 3)' => CashRegister::MODE_BLUETOOTH])
                ->setFormTypeOption('placeholder', 'Déduit : serveur si un lecteur est rattaché, sinon manuel')
                ->setHelp('Tap to Pay et Bluetooth exigent l\'autorisation du site (fiche Entreprise, panneau « Terminal de paiement Stripe ») ; l\'application parle alors au lecteur avec le kit Stripe Terminal.'),
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
        if ($register->isSdkMode() && !$this->container->get(\App\Services\PosTerminalService\PosTerminalSettingsService::class)->modeAllowed($register->effectiveTerminalMode())) {
            $this->addFlash('warning', 'Mode enregistré, mais ce mode n\'est pas autorisé sur le site : activez-le dans la fiche Entreprise (panneau « Terminal de paiement Stripe »), sinon le POS répondra TERMINAL_MODE_NOT_ALLOWED.');
        }
        if ($register->getTerminalMode() === CashRegister::MODE_SERVER && !$register->hasTerminal()) {
            $this->addFlash('warning', 'Mode « piloté par le serveur » sans lecteur rattaché et activé : le terminal restera indisponible.');
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
            ['after' => ['name' => $entityInstance->getName(), 'isActive' => $entityInstance->isActive(), 'terminalEnabled' => $entityInstance->isTerminalEnabled(), 'readerId' => $entityInstance->getStripeReaderId(), 'terminalMode' => $entityInstance->getTerminalMode()], 'via' => 'easyadmin'], null, $entityInstance, 'cash_register', $entityInstance->getId());
    }

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [\App\Services\PosAuditService\PosAuditService::class => \App\Services\PosAuditService\PosAuditService::class,
            \App\Services\PosTerminalService\PosTerminalPaymentService::class => \App\Services\PosTerminalService\PosTerminalPaymentService::class,
            \App\Services\PosTerminalService\PosTerminalSettingsService::class => \App\Services\PosTerminalService\PosTerminalSettingsService::class];
    }
}
