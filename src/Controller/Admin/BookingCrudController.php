<?php
// src/Controller/Admin/BookingCrudController.php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class BookingCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('startAt', 'Date de début'))
            ->add(DateTimeFilter::new('endAt', 'Date de fin'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        $tenantEm = $this->emProvider->getEntityManager();

        yield AssociationField::new('product', 'Produit')
            ->setFormTypeOptions([
                'em' => $tenantEm,
                'query_builder' => function (ProductRepository $repo) {
                    return $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                }
            ]);
        
        yield DateTimeField::new('startAt', 'Début');
        yield DateTimeField::new('endAt', 'Fin');
        
        yield IntegerField::new('quantity', 'Qté');
        
        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'Panier' => 'CART',
                'Confirmé' => 'CONFIRMED',
                'Annulé' => 'CANCELLED',
                'Remboursé' => 'REFUNDED',
            ])
            ->renderAsBadges([
                'CONFIRMED' => 'success',
                'cart' => 'secondary',
                'CART' => 'secondary',
                'CANCELLED' => 'danger',
                'REFUNDED' => 'warning',
            ]);
    }
}