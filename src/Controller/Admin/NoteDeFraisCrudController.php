<?php
namespace App\Controller\Admin;

use App\Entity\Collections;
use App\Entity\NoteDeFrais;
use App\Entity\TypeNoteDeFrais;
use App\Repository\CollectionsRepository;
use App\Repository\TypeNoteDeFraisRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NoteDeFraisCrudController extends AbstractCrudController
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
        return NoteDeFrais::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de la Note de Frais')->onlyOnIndex(),
            TextEditorField::new('description', 'Description'),
            MoneyField::new('montant', 'Montant')->setCurrency('EUR')->setStoredAsCents(false),
            DateField::new('date', 'Date'),

            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
            AssociationField::new('Collection', 'Collection Associée')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionsRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.nomCollection', 'ASC');
                    },
                    'choice_label' => 'nomCollection',
                ]),
            AssociationField::new('typeNoteDeFrais', 'Type de Note de Frais')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TypeNoteDeFraisRepository $repo) {
                        return $repo->createQueryBuilder('tndf')->orderBy('tndf.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            ImageField::new('typeNoteDeFrais.image', 'Logo note de frais')
                ->setBasePath('/assets/images/')
                ->onlyOnIndex(),
        ];
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(NoteDeFrais::class)->createQueryBuilder('entity');
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