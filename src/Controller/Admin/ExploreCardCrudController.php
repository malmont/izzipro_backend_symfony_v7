<?php
namespace App\Controller\Admin;

use App\Entity\ExploreCard;
use App\Form\ExploreCardTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\{
    IdField,
    BooleanField,
    TextField,
    CollectionField,
    ImageField,
    TextareaField
};
use Symfony\Component\Form\Extension\Core\Type\FileType;


class ExploreCardCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ExploreCard::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Carte d’Exploration')
            ->setEntityLabelInPlural('Cartes d’Exploration')
            ->setSearchFields(['standardTitle','differentTitle','description','link'])
            ->setDefaultSort(['id'=>'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield BooleanField::new('isDifferent', 'Mode Vidéo ?');
        yield TextareaField::new('standardTitle', 'standardTitle')
            ->setRequired(false)
            ->onlyOnForms();
        yield TextareaField::new('differentTitle', 'differentTitle')
            ->setRequired(false)
            ->onlyOnForms();    
        yield TextareaField::new('description', 'Description')
            ->setRequired(false)
            ->onlyOnForms();
        yield TextField::new('link', 'Lien')
            ->setRequired(false)
            ->onlyOnForms();

        yield ImageField::new('imagePath', 'Image de la carte')
            ->setBasePath('/assets/uploads/explore/')
            ->setUploadDir('public/assets/uploads/explore')
            ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
            ->setRequired(false)
            ->onlyOnForms();

        yield TextField::new('videoPath', 'Fichier vidéo')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'mapped'   => false, 
                'required' => false,
                'attr'     => ['accept'=>'video/mp4']
            ])
            ->onlyOnForms();

        yield TextField::new('videoPath', 'Lien vidéo')
            ->setRequired(false)
            ->onlyOnForms();  
            
        yield CollectionField::new('translations', 'Contenus Traduits')
            ->setEntryType(ExploreCardTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
    }
}