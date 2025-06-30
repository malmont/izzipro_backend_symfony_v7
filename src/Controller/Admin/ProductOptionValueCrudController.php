<?php

namespace App\Controller\Admin;

use App\Entity\ProductOptionValue;
use App\Repository\ProductOptionRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Controller\Admin\BaseTenantCrudController; 

class ProductOptionValueCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return ProductOptionValue::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('value', 'Valeur de l\'option'),
            AssociationField::new('productOption', 'Type d\'option parent')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(ProductOptionRepository $repo) => $repo->createQueryBuilder('po')->orderBy('po.name', 'ASC'),
                ]),
        ];
    }

 
}