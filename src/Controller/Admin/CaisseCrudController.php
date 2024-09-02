<?php
namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Security;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

class CaisseCrudController extends AbstractCrudController
{
    private $em;
    private $security;
    private $adminUrlGenerator;

    public function __construct(EntityManagerInterface $em, Security $security,AdminUrlGenerator $adminUrlGenerator)
    {
        $this->em = $em;
        $this->security = $security;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Caisse::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Caisse')
            ->setEntityLabelInPlural('Caisses')
            ->setSearchFields(['amountTotal', 'createdAt']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            NumberField::new('amountTotal', 'Montant Total'),
            DateField::new('createdAt', 'Date de Création')->setFormat('dd/MM/yyyy')->hideOnForm(),
            BooleanField::new('isOpen', 'Ouverte')->renderAsSwitch(false),
            AssociationField::new('transactionCaisses', 'Transactions')
                ->formatValue(function ($value, $entity) {
                    $transactionCaissesUrl = $this->adminUrlGenerator
                        ->setController(TransactionCaisseListController::class)
                        ->setAction('index')
                        ->set('caisseId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les transactions</a>',
                        $transactionCaissesUrl
                    );
                })
                ->renderAsHtml()
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('amountTotal')
            ->add('createdAt');
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Caisse) {
            // Vérifier s'il existe déjà une caisse ouverte
            $existingCaisse = $this->getOpenCaisse();
            if ($existingCaisse) {
                // Empêcher la création d'une nouvelle caisse si une caisse est déjà ouverte
                throw new \Exception('A caisse is already open. Please close it before opening a new one.');
            }

            // Définir isOpen à true
            $entityInstance->setOpen(true);
            $entityInstance->setCreatedAt(new \DateTime());

            // Persister la caisse avant de créer la transaction
            $this->em->persist($entityInstance);
            $this->em->flush();

            // Créer une transaction d'ouverture de caisse
            $transactionType = $this->em->getRepository(TransactionType::class)->findOneBy(['name' => 'Ouverture']);
            $transactionCaisse = new TransactionCaisse();
            $transactionCaisse->setCaisse($entityInstance);
            $transactionCaisse->setUserCaisse($this->security->getUser());
            $transactionCaisse->setTransactionDate(new \DateTime());
            $transactionCaisse->setTransactionType($transactionType);
            $transactionCaisse->setAmount(0.0); // Pas de montant pour l'ouverture

            $this->em->persist($transactionCaisse);
            $this->em->flush();
        }

        parent::persistEntity($em, $entityInstance);
    }

    /**
     * Méthode pour obtenir la caisse ouverte.
     *
     * @return Caisse|null
     */
    private function getOpenCaisse(): ?Caisse
    {
        return $this->em->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
}

