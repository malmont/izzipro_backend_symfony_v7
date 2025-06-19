<?php
namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\Collections;
use App\Entity\Fournisseur;
use App\Entity\CollectionPicture;
use App\Repository\CollectionsRepository;
use App\Repository\FournisseurRepository;
use App\Repository\CollectionPictureRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CommandeCrudController extends AbstractCrudController
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
        return Commande::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            MoneyField::new('budget', 'Budget')->setCurrency('USD')->setStoredAsCents(false),
            DateField::new('date', 'Date'),
            TextField::new('name', 'Nom de la Commande'),
            BooleanField::new('isClosed', 'isClosed'),

            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
            AssociationField::new('collections', 'Collections')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionsRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.nomCollection', 'ASC');
                    },
                    'choice_label' => 'nomCollection',
                ]),
            AssociationField::new('fournisseur', 'Fournisseur Associé')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (FournisseurRepository $repo) {
                        return $repo->createQueryBuilder('f')->orderBy('f.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            AssociationField::new('commandepictures', 'Image de la collection')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionPictureRepository $repo) {
                        return $repo->createQueryBuilder('cp')->orderBy('cp.id', 'ASC');
                    },
                    'choice_label' => 'imageUrl',
                ]),
            
            ImageField::new('commandepictures.imageUrl', 'Aperçu de l\'image')
                ->setBasePath('assets/images/')
                ->onlyOnIndex(),
        ];
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Commande::class)->createQueryBuilder('entity');
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