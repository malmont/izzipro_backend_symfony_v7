<?php
namespace App\Controller\Admin;

use App\Entity\CashDetails;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class CashDetailsListController extends AbstractCrudController
{
    private EntityManagerInterface $em;
    private RequestStack $requestStack;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return CashDetails::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $request = $this->requestStack->getCurrentRequest();
        $transactionId = $request->query->get('transactionId');

        return $this->em->getRepository(CashDetails::class)
            ->createQueryBuilder('cd')
            ->where('cd.transactionCaisse = :transactionId')
            ->setParameter('transactionId', $transactionId);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('transactionCaisse', 'Transaction'),
            AssociationField::new('typeCash', 'Type de Cash'),
            IntegerField::new('nombreItems', 'Nombre d\'items'),
        ];
    }
}
