<?php
namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Core\Security;


class CaisseCrudController extends BaseTenantCrudController
{
    private Security $security;
    private AdminUrlGenerator $adminUrlGenerator;


    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        Security $security,
        AdminUrlGenerator $adminUrlGenerator
    ) {
        parent::__construct($emProvider);
        $this->security = $security;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Caisse::class;
    }


    public function configureCrud(Crud $crud): Crud { /*...*/ }
    public function configureFields(string $pageName): iterable { /*...*/ }
    public function configureFilters(Filters $filters): Filters { /*...*/ }
    

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();

        if ($entityInstance instanceof Caisse) {
            $existingCaisse = $this->getOpenCaisse();
            if ($existingCaisse) {
                throw new \Exception('A caisse is already open. Please close it before opening a new one.');
            }

            $entityInstance->setOpen(true);
            $entityInstance->setCreatedAt(new \DateTime());

            $tenantEm->persist($entityInstance);
            $tenantEm->flush();

            $transactionType = $tenantEm->getRepository(TransactionType::class)->findOneBy(['name' => 'Ouverture']);
            if (!$transactionType) {
                throw new \Exception("Le type de transaction 'Ouverture' est introuvable.");
            }
            $user = $this->security->getUser();

            $transactionCaisse = new TransactionCaisse();
            $transactionCaisse->setCaisse($entityInstance);
            $transactionCaisse->setUserCaisse($user);
            $transactionCaisse->setTransactionDate(new \DateTime());
            $transactionCaisse->setTransactionType($transactionType);
            $transactionCaisse->setAmount(0.0);

            $tenantEm->persist($user);
            $tenantEm->persist($transactionType);
            
            $tenantEm->persist($transactionCaisse);
            $tenantEm->flush();
        }
    }

    private function getOpenCaisse(): ?Caisse
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }

}