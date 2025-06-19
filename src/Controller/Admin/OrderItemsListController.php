<?php
namespace App\Controller\Admin;

use App\Entity\OrderItems;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;

class OrderItemsListController extends AbstractCrudController
{
    // MODIFICATION 1 : On injecte notre provider
    private TenantEntityManagerProvider $emProvider;
    private RequestStack $requestStack;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        RequestStack $requestStack
    ) {
        $this->emProvider = $emProvider;
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // La logique pour désactiver l'action "new" est conservée
        return $actions
            ->disable(Action::NEW);
    }

    /**
     * MODIFICATION 2 : La méthode utilise maintenant l'EM du tenant.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $request = $this->requestStack->getCurrentRequest();
        $orderId = $request->query->get('orderId');
        
        $qb = $tenantEm->getRepository(OrderItems::class)
            ->createQueryBuilder('oi');

        if ($orderId) {
            $qb->where('oi.orderAssociated = :orderId')
               ->setParameter('orderId', $orderId);
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        // La configuration des champs reste la même
        return [
            TextField::new('productVariant.product.name', 'Produit'),
            TextField::new('productVariant.size', 'Taille'),
            TextField::new('productVariant.color', 'Couleur'),
            ImageField::new('productVariant.product.image', 'Image')
                ->setBasePath('assets/uploads/products/')
                ->setUploadDir('public/assets/uploads/products/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            NumberField::new('quantity', 'Quantité'),
            MoneyField::new('unitPrice', 'Prix unitaire')->setCurrency('USD')->setStoredAsCents(false),
            MoneyField::new('totalPrice', 'Total')->setCurrency('USD')->setStoredAsCents(false),
        ];
    }
    
    /**
     * MODIFICATION 3 : La méthode privée est aussi mise à jour
     */
    private function getOrderItemsByOrderId(int $orderId): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(OrderItems::class)->findBy(['orderAssociated' => $orderId]);
    }
    
    /**
     * MODIFICATION 4 : On ajoute les méthodes d'écriture par sécurité (programmation défensive)
     * au cas où vous réactiveriez les actions d'écriture plus tard.
     */
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