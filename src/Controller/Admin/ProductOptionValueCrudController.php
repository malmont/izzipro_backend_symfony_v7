<?php

namespace App\Controller\Admin;

use App\Entity\ProductOptionValue;
use App\Repository\ProductOptionRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Controller\Admin\BaseTenantCrudController;
use App\Form\ProductOptionValueTranslationType; 


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
            CollectionField::new('translations', 'Valeur par langue')
                ->setEntryType(ProductOptionValueTranslationType::class) // <-- Il faudra créer ce FormType
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            AssociationField::new('productOption', 'Type d\'option parent')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(ProductOptionRepository $repo) => $repo->createQueryBuilder('po')->orderBy('po.name', 'ASC'),
                ]),
        ];
    }

 
}