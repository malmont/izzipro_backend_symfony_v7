<?php
namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class ProductVariantListController extends AbstractCrudController
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
        return ProductVariant::class;
    }

    /**
     * Cette méthode est utilisée pour filtrer les variantes de produit par l'ID du produit passé via l'URL.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // Récupérer la requête actuelle
        $request = $this->requestStack->getCurrentRequest();
        $productId = $request->query->get('productId');
        
        return $this->em->getRepository(ProductVariant::class)
            ->createQueryBuilder('pv')
            ->where('pv.product = :productId')
            ->setParameter('productId', $productId);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('size', 'size'),
            NumberField::new('stockQuantity', 'Stock'),
            TextField::new('color', 'color'),
            TextField::new('product.name', 'Produit'),
        ];
    }

    /**
     * Cette méthode récupère les variantes de produit associées à un produit spécifique.
     */
    private function getProductVariantsByProductId(int $productId): array
    {
        return $this->em->getRepository(ProductVariant::class)->findBy(['product' => $productId]);
    }
}
