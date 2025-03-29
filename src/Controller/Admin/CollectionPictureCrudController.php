<?php

namespace App\Controller\Admin;

use App\Entity\CollectionPicture;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class CollectionPictureCrudController extends AbstractCrudController
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
