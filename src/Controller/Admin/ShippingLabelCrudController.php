<?php
namespace App\Controller\Admin;

use App\Entity\Parcel;
use App\Entity\ShippingLabel;
use App\Repository\ParcelRepository;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

// 2. On étend notre contrôleur de base
class ShippingLabelCrudController extends BaseTenantCrudController
{
    // 3. Le constructeur est SUPPRIMÉ. Le parent s'en occupe !
    //    Symfony injectera automatiquement TenantEntityManagerProvider dans le constructeur du parent.

    public static function getEntityFqcn(): string
    {
        return ShippingLabel::class;
    }

    // 4. On conserve configureFields car il est spécifique ET il a besoin de l'emProvider du parent
    public function configureFields(string $pageName): iterable
    {
        // $this->emProvider est accessible car il est 'protected' dans le parent
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('parcel', 'Colis')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ParcelRepository $repo) {
                        return $repo->createQueryBuilder('p')->orderBy('p.id', 'DESC');
                    },
                    'choice_label' => 'id', // ou une autre propriété de Parcel
                ]),
            UrlField::new('labelUrl', 'URL Étiquette')->hideOnForm(),
            TextField::new('trackingCode', 'Tracking Code')->onlyOnIndex(),
            DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex(),
        ];
    }
    
    // 5. Les méthodes createIndexQueryBuilder, persistEntity, updateEntity, et deleteEntity
    //    ont été SUPPRIMÉES car la logique de base du parent est suffisante.
}