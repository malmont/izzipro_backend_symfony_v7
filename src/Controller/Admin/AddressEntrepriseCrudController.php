<?php
namespace App\Controller\Admin;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AddressEntrepriseCrudController extends AbstractCrudController
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
        return AddressEntreprise::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Adresse Entreprise')
            ->setEntityLabelInPlural('Adresses Entreprises')
            ->setPageTitle(Crud::PAGE_INDEX, 'Adresses Entreprises');
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        // Lien vers l'entité Entreprise
        yield AssociationField::new('entreprise', 'Entreprise')
            // ✅ On force le QueryBuilder et l'EM pour ce champ
            ->setFormTypeOptions([
                'em' => $tenantEm,
                'query_builder' => function (EntrepriseRepository $repo) {
                    return $repo->createQueryBuilder('e')->orderBy('e.name', 'ASC');
                },
                'choice_label' => 'name',
            ]);

        // Champs d'adresse
        yield TextField::new('street1', 'Rue N°1');
        yield TextField::new('street2', 'Rue N°2');
        yield TextField::new('city', 'Ville');
        yield TextField::new('state', 'État/Province');
        yield TextField::new('zip', 'Code Postal');
        yield TextField::new('country', 'Pays (ISO)');

        // Coordonnées
        yield TextField::new('phone', 'Téléphone');
        yield TextField::new('email', 'E-mail');
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(AddressEntreprise::class)->createQueryBuilder('entity');
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