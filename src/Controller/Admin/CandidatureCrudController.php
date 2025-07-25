<?php

namespace App\Controller\Admin;

use App\Entity\Candidature;
use App\Entity\Emploi;
use App\Repository\EmploiRepository;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class CandidatureCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Candidature::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('nomComplet', 'Nom complet'),
            EmailField::new('courriel', 'Courriel'),
            TelephoneField::new('tel', 'Téléphone'),
            
            AssociationField::new('emploi', 'Offre d\'emploi')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (EmploiRepository $repo) {
                        return $repo->createQueryBuilder('e')->orderBy('e.titre', 'ASC');
                    },
                    'choice_label' => 'titre',
                ]),

            TextField::new('adresse')->hideOnIndex(),
            TextField::new('ville')->hideOnIndex(),
            TextField::new('codePostal', 'Code Postal')->hideOnIndex(),
            TextField::new('anneeExperience', 'Années d\'expérience'),
            DateField::new('dateDisponibilite', 'Disponible le'),
            TextareaField::new('questionCommentaire', 'Questions / Commentaires')->hideOnIndex(),
            TextField::new('niveauAnglais', 'Niveau d\'anglais'),
            TextField::new('succursale', 'Succursale souhaitée'),
            UrlField::new('lienCv', 'Lien vers le CV')
                ->setTemplatePath('admin/fields/link_to_cv.html.twig'),
        ];
    }
}