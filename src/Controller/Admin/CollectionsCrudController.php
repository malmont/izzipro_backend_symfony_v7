<?php
namespace App\Controller\Admin;

use App\Entity\CollectionPicture;
use App\Entity\Collections;
use App\Entity\User;
use App\Repository\CollectionPictureRepository;
use App\Repository\UserRepository;
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


class CollectionsCrudController extends AbstractCrudController
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
        return Collections::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('nomCollection', 'Nom de la Collection'),
            MoneyField::new('budgetCollection', 'Budget')->setCurrency('USD')->setStoredAsCents(false),
            DateField::new('startDateCollection', 'Date de Début'),
            DateField::new('endDateCollection', 'Date de Fin'),
            BooleanField::new('del', 'Supprimé'),
            BooleanField::new('isClosed', 'isClosed'),

            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
            AssociationField::new('userCollections', 'Utilisateur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC');
                    },
                    'choice_label' => 'email',
                ]),
            AssociationField::new('photoCollections', 'Image de la collection')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CollectionPictureRepository $repo) {
                        return $repo->createQueryBuilder('cp')->orderBy('cp.id', 'ASC');
                    },
                    'choice_label' => 'imageUrl', // ou une autre propriété pertinente
                ]),

            ImageField::new('photoCollections.imageUrl', 'Aperçu de l\'image')
                ->setBasePath('assets/images/') // Assurez-vous que ce chemin est correct
                ->onlyOnIndex(),
        ];
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Collections::class)->createQueryBuilder('entity');
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