<?php

namespace App\Controller\Admin;

use App\Entity\NewsletterSubscriber;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;


class NewsletterSubscriberCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsletterSubscriber::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Inscription Newsletter')
            ->setEntityLabelInPlural('Inscriptions Newsletter')
            ->setPageTitle('index', 'Liste des inscrits à la newsletter')
            ->setDefaultSort(['subscribedAt' => 'DESC']); 
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            
            EmailField::new('email', 'E-mail'),
            
            DateTimeField::new('subscribedAt', 'Date d\'inscription')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->hideWhenCreating() 
                ->setFormTypeOption('disabled', true), 
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::EDIT);
    }
}