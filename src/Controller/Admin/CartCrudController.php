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
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null) // devise du site (07/11/2026)
    {
        parent::__construct($emProvider);
    }

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
            TextField::new('carrierName', 'Transporteur'),
            MoneyField::new('CarrierPrice', 'Livraison')->setCurrency($this->currency?->code() ?? 'CAD'),
            MoneyField::new('subTotalHT', 'Sous-total HT')->setCurrency($this->currency?->code() ?? 'CAD'),
            MoneyField::new('taxe', 'TVA')->setCurrency($this->currency?->code() ?? 'CAD'),
            MoneyField::new('subTotalTTC', 'Sous-total TTC')->setCurrency($this->currency?->code() ?? 'CAD'),
            BooleanField::new('isPaid', 'Payé'),
        ];
    }
    
}
