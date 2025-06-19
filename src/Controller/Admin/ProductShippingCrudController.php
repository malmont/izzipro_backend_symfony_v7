<?php
namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductShipping;
use App\Entity\ShippingClass;
use App\Repository\ProductRepository;
use App\Repository\ShippingClassRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProductShippingCrudController extends AbstractCrudController
{
    /**
     * 1. On injecte notre provider
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public static function getEntityFqcn(): string
    {
        return ProductShipping::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),

            // ✅ On force le QueryBuilder et l'EM pour ce champ
            AssociationField::new('product', 'Produit')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductRepository $repo) {
                        return $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            NumberField::new('weight', 'Poids (kg)')
                ->setNumDecimals(2)
                ->setRequired(true),

            NumberField::new('length', 'Longueur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('width', 'Largeur (cm)')->setNumDecimals(1)->hideOnIndex(),
            NumberField::new('height', 'Hauteur (cm)')->setNumDecimals(1)->hideOnIndex(),

            // ✅ On force le QueryBuilder et l'EM pour ce champ aussi
            AssociationField::new('shippingClassEntity', 'Classe d’expédition')
                ->setRequired(false)
                ->setHelp('Laisse vide pour l’instant si non configuré')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ShippingClassRepository $repo) {
                        return $repo->createQueryBuilder('sc')->orderBy('sc.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
        ];
    }
    
    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(ProductShipping::class)->createQueryBuilder('entity');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}