<?php
namespace App\Controller\Admin;

use App\Entity\Collections;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Repository\CollectionsRepository;
use App\Repository\TypeNoteDeFraisRepository;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


class NoteDeFraisCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return NoteDeFrais::class;
    }

    public function configureFields(string $pageName): iterable
    {

        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de la Note de Frais')->onlyOnIndex(),
            TextEditorField::new('description', 'Description'),
            MoneyField::new('montant', 'Montant')->setCurrency('EUR')->setStoredAsCents(false),
            DateField::new('date', 'Date'),
            AssociationField::new('Collection', 'Collection Associée')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionsRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.nomCollection', 'ASC');
                    },
                    'choice_label' => 'nomCollection',
                ]),
            AssociationField::new('typeNoteDeFrais', 'Type de Note de Frais')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TypeNoteDeFraisRepository $repo) {
                        return $repo->createQueryBuilder('tndf')->orderBy('tndf.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            ImageField::new('typeNoteDeFrais.image', 'Logo note de frais')
                ->setBasePath('/assets/images/')
                ->onlyOnIndex(),
        ];
    }
}