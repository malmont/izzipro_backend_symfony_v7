<?php
namespace App\Controller\Admin;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

// 2. On étend notre contrôleur de base
class AddressEntrepriseCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return AddressEntreprise::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Adresse Entreprise')
            ->setEntityLabelInPlural('Adresses Entreprises')
            ->setPageTitle(Crud::PAGE_INDEX, 'Adresses Entreprises');
    }

    public function configureFields(string $pageName): iterable
    {

        $tenantEm = $this->emProvider->getEntityManager();

        yield AssociationField::new('entreprise', 'Entreprise')
            ->setFormTypeOptions([
                'em' => $tenantEm,
                'query_builder' => function (EntrepriseRepository $repo) {
                    return $repo->createQueryBuilder('e')->orderBy('e.name', 'ASC');
                },
                'choice_label' => 'name',
            ]);


        yield TextField::new('street1', 'Rue N°1');
        yield TextField::new('street2', 'Rue N°2');
        yield TextField::new('city', 'Ville');
        yield TextField::new('state', 'État/Province');
        yield TextField::new('zip', 'Code Postal');
        yield TextField::new('country', 'Pays (ISO)');


        yield TextField::new('phone', 'Téléphone');
        yield TextField::new('email', 'E-mail');
    }
    
}