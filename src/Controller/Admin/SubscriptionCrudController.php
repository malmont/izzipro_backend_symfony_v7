<?php

namespace App\Controller\Admin;

use App\Entity\Subscription;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Abonnements des clients (lecture) : l'état vient de Stripe (webhooks) ; les actions se font depuis le compte client ou Stripe */
class SubscriptionCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
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
            ->setHelp('index', 'Lecture seule : l\'état est recopié depuis Stripe. Annulation, pause et changement de formule se font depuis le compte du client (ou le tableau de bord Stripe).');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE)->add(Crud::PAGE_INDEX, Action::DETAIL);
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
        yield AssociationField::new('address', 'Adresse')->onlyOnDetail();
        yield AssociationField::new('carrier', 'Transporteur')->onlyOnDetail();
        yield TextField::new('stripeSubscriptionId', 'Abonnement Stripe')->onlyOnDetail();
        yield TextField::new('stripeCustomerId', 'Client Stripe')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Créé le');
        yield DateTimeField::new('canceledAt', 'Résilié le')->onlyOnDetail();
    }
}
