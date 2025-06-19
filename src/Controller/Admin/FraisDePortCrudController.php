<?php
namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Repository\CommandeRepository;
use App\Repository\TransporteurRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class FraisDePortCrudController extends AbstractCrudController
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
        return FraisDePort::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextField::new('facture', 'Facture'),
            TextField::new('tracknumber', 'Numéro de suivi'),
            MoneyField::new('price', 'Prix')->setCurrency('EUR')->setStoredAsCents(false),

            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
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

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(FraisDePort::class)->createQueryBuilder('entity');
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