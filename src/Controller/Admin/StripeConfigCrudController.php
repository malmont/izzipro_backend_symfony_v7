<?php

namespace App\Controller\Admin;

use App\Entity\StripeConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Controller\Admin\BaseTenantCrudController;

class StripeConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StripeConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuration Stripe')
            ->setEntityLabelInPlural('Configuration Stripe')
            ->setPageTitle(Crud::PAGE_INDEX, 'Passerelle de Paiement Stripe')
            ->setHelp(Crud::PAGE_INDEX, 'Connectez ou gérez votre compte Stripe Connect pour encaisser les paiements par carte bancaire de vos clients.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $connectStripe = Action::new('connectStripe', 'Connecter / Configurer Stripe', 'fab fa-stripe')
            ->linkToRoute('admin_stripe_connect')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-primary');

        $disconnectStripe = Action::new('disconnectStripe', 'Déconnecter', 'fas fa-unlink')
            ->linkToRoute('admin_stripe_disconnect')
            ->setCssClass('text-danger')
            ->displayIf(fn(StripeConfig $config) => (bool) $config->getAccountId());

        return $actions
            ->add(Crud::PAGE_INDEX, $connectStripe)
            ->add(Crud::PAGE_INDEX, $disconnectStripe)
            ->disable(Action::NEW); // Empêche la création manuelle d'un compte sans passer par l'onboarding Stripe
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id', 'ID')->hideOnForm(),
            TextField::new('accountId', 'Identifiant Stripe Connect (Account ID)')
                ->setHelp('Identifiant du compte Express connecté sur votre plateforme Stripe.'),
            BooleanField::new('isActive', 'Paiements Actifs')
                ->renderAsSwitch(false)
                ->setHelp('Indique si les paiements par carte bancaire sont autorisés et fonctionnels.'),
        ];
    }
}
