<?php

namespace App\Controller\Admin;

use App\Entity\TypeCash;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

/** Coupures du site ; bouton « Créer les coupures de la devise » (F18 partie 1, 01/11/2026) : idempotent, rien si la devise du site a déjà une ligne */
class TypeCashCrudController extends BaseTenantCrudController
{
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly \App\Services\CaisseService\DenominationService $denominations, private readonly \App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency, private readonly \EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator $adminUrls)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return TypeCash::class;
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return parent::configureActions($actions)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('createDefaults', 'Créer les coupures de la devise', 'fa fa-coins')->linkToCrudAction('createDefaults')->createAsGlobalAction());
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setHelp('index', 'Coupures servies à la caisse pour la devise du site (fiche Entreprise). « Créer les coupures de la devise » ajoute la liste par défaut (CAD, EUR, USD) seulement si aucune coupure n\'existe encore dans cette devise ; rien n\'est modifié ni supprimé.');
    }

    /** Liste par défaut de la devise du site, seulement si elle n'a aucune coupure ; journal DENOMINATIONS_CREATED (source easyadmin) */
    public function createDefaults(): \Symfony\Component\HttpFoundation\Response
    {
        $currency = strtoupper($this->currency->code());
        if ($this->denominations->hasAny($currency)) {
            $this->addFlash('info', sprintf('Le site a déjà des coupures en %s : rien n\'a été créé.', $currency));
        } elseif (!\App\Services\CaisseService\DenominationDefaults::has($currency)) {
            $this->addFlash('warning', sprintf('Aucune liste par défaut pour la devise %s : saisissez les coupures à la main.', $currency));
        } else {
            $this->addFlash('success', sprintf('%d coupures en %s créées.', $this->denominations->createDefaults($currency, 'easyadmin'), $currency));
        }

        return $this->redirect($this->adminUrls->setController(self::class)->setAction(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::INDEX)->generateUrl());
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')
                ->setHelp('Exemple : Billet 100$, Billet 50$, Pièce 2$')
                ->setRequired(true),
            MoneyField::new('value', 'Valeur')->setCurrency('USD'),
            // Coupures servies au POS (19/10/2026, GET /api/caisse/denominations)
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('valueCents', 'Valeur (cents)')->setHelp('Utilisée par la caisse ; vide : calculée depuis la valeur'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('kind', 'Genre')->setChoices(['Billet' => 'bill', 'Pièce' => 'coin'])->setRequired(false),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextField::new('currency', 'Devise (ISO)')->setHelp('CAD, EUR…'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('position', 'Ordre'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField::new('active', 'Active')->renderAsSwitch(false),

        ];
    }
}
