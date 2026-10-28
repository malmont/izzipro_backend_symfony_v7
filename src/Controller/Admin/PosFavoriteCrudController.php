<?php

namespace App\Controller\Admin;

use App\Entity\PosFavorite;
use App\Entity\Product;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Favoris de la caisse (F10) : produits épinglés dans la grille de l'écran de vente, pour tout le site (poste vide) ou pour un poste.
 * Les épinglés d'un poste REMPLACENT ceux du site pour ce poste. Même liste que PUT /api/pos/favorites/pins ; journal FAVORITES_UPDATED.
 */
class PosFavoriteCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly PosAuditService $audit)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return PosFavorite::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Favori de la caisse')
            ->setEntityLabelInPlural('Favoris de la caisse')
            ->setDefaultSort(['cashRegister' => 'ASC', 'position' => 'ASC', 'id' => 'ASC'])
            ->setHelp('index', 'Grille de l\'écran de vente : épinglés d\'abord (ordre croissant de position), puis les plus vendus. Poste vide : tout le site ; un poste qui a ses propres épinglés n\'affiche que les siens. Réglages : fiche Entreprise, panneau « Grille de favoris (caisse) ».');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('product', 'Produit')->setRequired(true)
                ->setFormTypeOption('query_builder', fn (EntityRepository $r) => $r->createQueryBuilder('p')->where('p.isPos = true')->andWhere('p.saleEnabled = true')->andWhere('p.rentalEnabled = false')->orderBy('p.name', 'ASC'))
                ->setHelp('Produits vendables en caisse seulement.'),
            AssociationField::new('productVariant', 'Variante précise')->setRequired(false)
                ->setFormTypeOption('query_builder', fn (EntityRepository $r) => $r->createQueryBuilder('v')->join('v.product', 'p')->where('p.isPos = true')->andWhere('p.saleEnabled = true')->andWhere('p.rentalEnabled = false')->orderBy('p.name', 'ASC')->addOrderBy('v.id', 'ASC'))
                ->setHelp('Facultatif : vide, la caisse demande la variante si le produit en a plusieurs. Une variante choisie fixe le produit.'),
            AssociationField::new('cashRegister', 'Poste')->setRequired(false)->setHelp('Vide : tout le site.'),
            IntegerField::new('position', 'Position')->setHelp('Ordre dans la grille (0 en premier).'),
            DateTimeField::new('createdAt', 'Créé le')->hideOnForm(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->alignProduct($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
        $this->journal($entityInstance, 'created');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->alignProduct($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
        $this->journal($entityInstance, 'updated');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $snapshot = $entityInstance instanceof PosFavorite ? ['register' => $entityInstance->getCashRegister(), 'productId' => $entityInstance->getProduct()?->getId(), 'variantId' => $entityInstance->getProductVariant()?->getId(), 'position' => $entityInstance->getPosition()] : null;
        parent::deleteEntity($entityManager, $entityInstance); // journal après la suppression (relecture du 28/10/2026) : rien au journal si elle échoue
        if ($snapshot !== null) {
            $register = $snapshot['register'];
            $this->audit->record(PosAuditService::FAVORITES_UPDATED, ['source' => 'easyadmin', 'action' => 'deleted', 'scope' => $register ? 'register' : 'site', 'cashRegisterId' => $register?->getId(),
                'pin' => ['productId' => $snapshot['productId'], 'variantId' => $snapshot['variantId'], 'position' => $snapshot['position']]], null, $register, $register ? 'cash_register' : null, $register?->getId());
        }
    }

    /** Variante choisie : son produit fait foi */
    private function alignProduct(mixed $favorite): void
    {
        if ($favorite instanceof PosFavorite && $favorite->getProductVariant()?->getProduct() instanceof Product) {
            $favorite->setProduct($favorite->getProductVariant()->getProduct());
        }
    }

    private function journal(mixed $favorite, string $action): void
    {
        if (!$favorite instanceof PosFavorite) {
            return;
        }
        $register = $favorite->getCashRegister();
        $this->audit->record(PosAuditService::FAVORITES_UPDATED, ['source' => 'easyadmin', 'action' => $action, 'scope' => $register ? 'register' : 'site', 'cashRegisterId' => $register?->getId(),
            'pin' => ['productId' => $favorite->getProduct()?->getId(), 'variantId' => $favorite->getProductVariant()?->getId(), 'position' => $favorite->getPosition()]], null, $register, $register ? 'cash_register' : null, $register?->getId());
    }
}
