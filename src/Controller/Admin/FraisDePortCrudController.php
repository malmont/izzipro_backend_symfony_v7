<?php
namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Repository\CommandeRepository;
use App\Repository\TransporteurRepository;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;


// 2. On étend notre contrôleur de base
class FraisDePortCrudController extends BaseTenantCrudController
{
    // 3. Le constructeur est SUPPRIMÉ. Le parent s'en occupe !

    public static function getEntityFqcn(): string
    {
        return FraisDePort::class;
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextField::new('facture', 'Facture'),
            TextField::new('tracknumber', 'Numéro de suivi'),
            MoneyField::new('price', 'Prix')->setCurrency('EUR')->setStoredAsCents(false),

            AssociationField::new('commande', 'Commande')
                ->autocomplete()
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CommandeRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.date', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),

            AssociationField::new('transporteur', 'Transporteur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TransporteurRepository $repo) {
                        return $repo->createQueryBuilder('t')->orderBy('t.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            ImageField::new('transporteur.logo', 'Logo du Transporteur')
                ->setBasePath('/assets/uploads/Carrier/')
                ->onlyOnIndex(),
        ];
    }

}