<?php
namespace App\Controller\Admin;

use App\Entity\CollectionPicture;
use App\Entity\Collections;
use App\Entity\User;
use App\Repository\CollectionPictureRepository;
use App\Repository\UserRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class CollectionsCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Collections::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('nomCollection', 'Nom de la Collection'),
            MoneyField::new('budgetCollection', 'Budget')->setCurrency('USD')->setStoredAsCents(false),
            DateField::new('startDateCollection', 'Date de Début'),
            DateField::new('endDateCollection', 'Date de Fin'),
            BooleanField::new('del', 'Supprimé'),
            BooleanField::new('isClosed', 'isClosed'),

            AssociationField::new('userCollections', 'Utilisateur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC');
                    },
                    'choice_label' => 'email',
                ]),
            AssociationField::new('photoCollections', 'Image de la collection')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionPictureRepository $repo) {
                        return $repo->createQueryBuilder('cp')->orderBy('cp.id', 'ASC');
                    },
                    'choice_label' => 'imageUrl',
                ]),

            ImageField::new('photoCollections.imageUrl', 'Aperçu de l\'image')
                ->setBasePath('assets/images/')
                ->onlyOnIndex(),
        ];
    }
}