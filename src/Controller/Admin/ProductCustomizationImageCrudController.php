<?php

namespace App\Controller\Admin;

use App\Entity\ProductCustomizationImage;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class ProductCustomizationImageCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return ProductCustomizationImage::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            ImageField::new('imagePath', 'Rendu Visuel Configuré')
                ->setBasePath('assets/uploads/customization/')
                ->setUploadDir('public/assets/uploads/customization/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(true)
                ->setColumns('col-md-12'),
            IntegerField::new('numberOfPieces', 'Nombre de pièces (ex: 1, 2, 3)')
                ->setColumns('col-md-6'),
            AssociationField::new('productVariant', 'Variante de Produit associée')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('pv')
                        ->join('pv.product', 'p')
                        ->orderBy('p.name', 'ASC'),
                ])
                ->setColumns('col-md-6'),
            AssociationField::new('optionValues', 'Combinaison d\'options (Manches, Col, Couleur...)')
                ->setHelp('Sélectionnez l\'ensemble des options représentées sur ce visuel.')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('pov')
                        ->join('pov.productOption', 'po')
                        ->orderBy('po.name', 'ASC')
                        ->addOrderBy('pov.value', 'ASC'),
                ])
                ->setColumns('col-md-12'),
        ];
    }
}
