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

class CaisseCrudController extends AbstractCrudController
{
    private $em;
    private $security;

    public function __construct(EntityManagerInterface $em, Security $security)
    {
        $this->em = $em;
        $this->security = $security;
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
            AssociationField::new('transactionCaisses', 'Transactions')->hideOnForm(),
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

