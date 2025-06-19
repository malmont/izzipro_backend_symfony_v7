<?php
namespace App\Controller\Admin;

use App\Entity\Adress;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;



class AdressCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Adress::class;
    }


    public function configureFields(string $pageName): iterable
    {

        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('firstname', 'Prénom'),
            TextField::new('lastname', 'Nom'),
            TextField::new('fullname', 'Nom complet')->hideOnForm(),
            TextField::new('company', 'Entreprise')->hideOnIndex(),
            TextareaField::new('address', 'Adresse'),
            TextareaField::new('complement', 'Complément d\'adresse')->hideOnIndex(),
            TextField::new('phone', 'Téléphone'),
            TextField::new('city', 'Ville'),
            TextField::new('codepostal', 'Code postal'),
            TextField::new('country', 'Pays'),
            AssociationField::new('userAdress', 'Utilisateur associé')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC');
                    },
                    'choice_label' => 'email',
                ]),
        ];
    }

}