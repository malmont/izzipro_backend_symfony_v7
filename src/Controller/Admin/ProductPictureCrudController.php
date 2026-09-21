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
                ->setBasePath('/bucket-simulator/assets/uploads/products/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(true),
        ];
    }
}
