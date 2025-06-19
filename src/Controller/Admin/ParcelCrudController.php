<?php
namespace App\Controller\Admin;

use App\Entity\Parcel;
use App\Entity\ShippingOrder;
use App\Repository\ShippingOrderRepository;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class ParcelCrudController extends AbstractCrudController
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
        return Parcel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            
            // ✅ On force le QueryBuilder et l'EM pour ce champ
            AssociationField::new('shippingOrder', 'Shipping Order')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ShippingOrderRepository $repo) {
                        return $repo->createQueryBuilder('so')->orderBy('so.createdAt', 'DESC');
                    },
                    'choice_label' => 'id', // ou une autre propriété de ShippingOrder
                ]),

            IntegerField::new('index', 'Colis #'),
            NumberField::new('weight', 'Poids (kg)'),
            NumberField::new('length', 'Longueur (cm)'),
            NumberField::new('width', 'Largeur (cm)'),
            NumberField::new('height', 'Hauteur (cm)'),
            NumberField::new('price', 'Prix')->setStoredAsCents(false)->onlyOnIndex(),
            AssociationField::new('shippingLabel', 'Étiquette')->hideOnForm(),
        ];
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Parcel::class)->createQueryBuilder('entity');
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