<?php
namespace App\Controller\Admin;

use App\Entity\StatusPayment;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use App\Form\StatusPaymentTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class StatusPaymentCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StatusPayment::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_SUPER_ADMIN');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom du Statut de Paiement'),
            TextEditorField::new('description', 'Description')->hideOnIndex(),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(StatusPaymentTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Référentiel réservé au super administrateur.');
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Référentiel réservé au super administrateur.');
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Référentiel réservé au super administrateur.');
        parent::deleteEntity($entityManager, $entityInstance);
    }
}