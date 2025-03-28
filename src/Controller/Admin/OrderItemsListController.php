<?php
namespace App\Controller\Admin;

use App\Entity\OrderItems;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

class OrderItemsListController extends AbstractCrudController
{
    private $em;
    private $requestStack;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Désactiver le bouton "Add Order Item"
        return $actions
            ->disable(Action::NEW); // Désactiver l'action "new"
    }

    /**
     * Cette méthode est utilisée pour filtrer les articles de commande par l'ID de commande passé via l'URL.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // Récupérer la requête actuelle
        $request = $this->requestStack->getCurrentRequest();
        $orderId = $request->query->get('orderId');
        
        return $this->em->getRepository(OrderItems::class)
            ->createQueryBuilder('oi')
            ->where('oi.orderAssociated = :orderId')
            ->setParameter('orderId', $orderId);
    }

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
                MoneyField::new('unitPrice', 'Prix unitaire')->setCurrency('USD'),
                MoneyField::new('totalPrice', 'Total')->setCurrency('USD'),
            ];
        }

    /**
     * Cette méthode récupère les articles de commande associés à une commande spécifique.
     */
    private function getOrderItemsByOrderId(int $orderId): array
    {
        return $this->em->getRepository(OrderItems::class)->findBy(['orderAssociated' => $orderId]);
    }
}
