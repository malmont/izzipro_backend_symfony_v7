<?php

namespace App\Controller\Admin;

use App\Entity\ProductPicture;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class ProductPictureCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProductPicture::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            ImageField::new('imageUrl', 'Image')
                ->setBasePath('assets/uploads/products/')
                ->setUploadDir('public/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(true),
        ];
    }
}
