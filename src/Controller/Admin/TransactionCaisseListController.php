<?php
namespace App\Controller\Admin;

use App\Entity\TransactionCaisse;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class TransactionCaisseListController extends AbstractCrudController
{
    private $em;
    private $requestStack;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack,AdminUrlGenerator $adminUrlGenerator)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return TransactionCaisse::class;
    }

    /**
     * Cette méthode est utilisée pour filtrer les transactions de caisse par l'ID de caisse passé via l'URL.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // Récupérer la requête actuelle
        $request = $this->requestStack->getCurrentRequest();
        $caisseId = $request->query->get('caisseId');
        
        return $this->em->getRepository(TransactionCaisse::class)
            ->createQueryBuilder('tc')
            ->where('tc.caisse = :caisseId')
            ->setParameter('caisseId', $caisseId);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('caisse'),
            AssociationField::new('userCaisse')->setLabel('User'),
            AssociationField::new('transactionType', 'Type de Transaction'),
            DateField::new('transactionDate', 'Date de Transaction'),
            MoneyField::new('amount', 'Montant')->setCurrency('USD'),      
            AssociationField::new('orderCaisse', 'Commande')->hideOnIndex(),
            AssociationField::new('payment', 'Paiement')->hideOnIndex(),
            AssociationField::new('cashdetails', 'Détails de cash')
                ->formatValue(function ($value, $entity) {
                    $cashDetailsUrl = $this->adminUrlGenerator
                        ->setController(CashDetailsListController::class)
                        ->setAction(Crud::PAGE_INDEX)
                        ->set('transactionId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les détails de cash</a>',
                        $cashDetailsUrl
                    );
                })
                ->renderAsHtml(),
        ];
    }
}
