<?php

namespace App\Controller\Admin;

use App\Entity\ProductOptionValue;
use App\Repository\ProductOptionRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
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
            TextField::new('value', 'Valeur de l\'option (ex: Manches Longues, Col V, Bleu Marine)'),
            TextField::new('code', 'Code technique / Référence (ex: LONG_SLEEVE, V_NECK)')->setColumns('col-md-6'),
            NumberField::new('priceDelta', 'Supplément prix (€ / $)')->setNumDecimals(2)->setColumns('col-md-6'),
            ImageField::new('imagePreview', 'Icône / Visuel d\'aperçu')
                ->setBasePath('assets/uploads/options/')
                ->setUploadDir('public/assets/uploads/options/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false)
                ->setColumns('col-md-12'),
            CollectionField::new('translations', 'Traductions de la valeur')
                ->setEntryType(ProductOptionValueTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            AssociationField::new('productOption', 'Type d\'option parent')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(ProductOptionRepository $repo) => $repo->createQueryBuilder('po')->orderBy('po.name', 'ASC'),
                ])
                ->setColumns('col-md-6'),
        ];
    }
}