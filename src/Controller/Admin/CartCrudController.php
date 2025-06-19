<?php

namespace App\Controller\Admin;

use App\Entity\Cart;
use phpDocumentor\Reflection\Types\Boolean;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Controller\Admin\BaseTenantCrudController;

class CartCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Cart::class;
    }

    public function configureCrud(Crud $crud):Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }
    
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('userCart.FullName', 'Client'),
            TextField::new('carrierName','Carrier Name'),
            MoneyField::new('CarrierPrice', 'Shipping')->setCurrency('USD'),
            MoneyField::new('subTotalHT', 'SubTotal HT')->setCurrency('USD'),
            MoneyField::new('taxe', 'TVA')->setCurrency('USD'),
            MoneyField::new('subTotalTTC', 'SubTotal TTC')->setCurrency('USD'),
            BooleanField::new('isPaid'),
        ];
    }
    
}
