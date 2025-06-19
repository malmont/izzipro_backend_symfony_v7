<?php

namespace App\Controller\Admin;

use App\Entity\CollectionPicture;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class CollectionPictureCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return CollectionPicture::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            ImageField::new('imageUrl', 'Image')
                ->setBasePath('assets/images/')
                ->setUploadDir('public/assets/images/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
}
