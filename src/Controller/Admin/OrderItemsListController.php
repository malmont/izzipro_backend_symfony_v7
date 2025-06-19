<?php
namespace App\Controller\Admin;

use App\Entity\OrderItems;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;


class OrderItemsListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;

    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        RequestStack $requestStack
    ) {
        parent::__construct($emProvider); 
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }


    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

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

    // On CONSERVE configureFields car il est spécifique
    public function configureFields(string $pageName): iterable
    {
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
    
    // On CONSERVE cette méthode privée car elle est spécifique
    private function getOrderItemsByOrderId(int $orderId): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(OrderItems::class)->findBy(['orderAssociated' => $orderId]);
    }

}