<?php
// src/Controller/Admin/ProductTypeCrudController.php
namespace App\Controller\Admin;

use App\Entity\ProductType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\TextType; // <-- Importation ajoutée

class ProductTypeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProductType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom du Type de Produit'),
            CollectionField::new('specifications_template', 'Modèle de Caractéristiques')
                // ✅ Indique que chaque item de la collection est un simple champ de texte.
                ->setEntryType(TextType::class)
                
                // ✅ Améliore l'interface en masquant les labels numériques (0, 1, 2...).
                ->setFormTypeOptions([
                    'entry_options' => ['label' => false],
                ])
                
                ->setHelp('Ajoutez les noms des caractéristiques pour ce type de produit. Ex: "Matière", "Coupe", "Pression"...')
        ];
    }
}
