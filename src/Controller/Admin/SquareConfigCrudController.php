<?php
namespace App\Controller\Admin;

use App\Entity\SquareConfig;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

// 2. On étend notre contrôleur de base
class SquareConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return SquareConfig::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('accessToken', 'Access Token')
                ->setTemplatePath('admin/field/masked_secret.html.twig'),
            TextField::new('applicationId', 'Application ID'),
            TextField::new('locationId', 'Location ID'),
            BooleanField::new('isActive', 'Actif')->renderAsSwitch(false),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('La configuration Square est en lecture seule.');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('La configuration Square est en lecture seule.');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        throw $this->createAccessDeniedException('La configuration Square est en lecture seule.');
    }
}