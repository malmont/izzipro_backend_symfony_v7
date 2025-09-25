<?php

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use App\Form\VideoTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class VideoCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Video::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre'),
            ImageField::new('imageDeFond', 'Image de fond')
                ->setBasePath('assets/uploads/slider/')
                ->setUploadDir('public/assets/uploads/slider/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            UrlField::new('lienVideo', 'Lien de la vidéo (YouTube, Vimeo, etc.)'),
            CollectionField::new('translations', 'Contenus par langue')
                ->setEntryType(VideoTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }


}
