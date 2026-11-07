<?php
namespace App\Controller\Admin;

use App\Entity\Parcel;
use App\Entity\ShippingOrder;
use App\Repository\ShippingOrderRepository;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;


class ParcelCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Parcel::class;
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Colis')->setEntityLabelInPlural('Colis')
            ->setHelp('index', 'Les colis calculés pour chaque expédition EasyPost (poids et dimensions retenus pour l\'étiquette). Consultation seulement.');
    }

    /** Écran de consultation (lot 1 bis, 07/11/2026) : ces lignes naissent de l'achat d'une étiquette, elles ne se créent ni ne se modifient à la main */
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return parent::configureActions($actions)
            ->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::EDIT, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            
            AssociationField::new('shippingOrder', 'Expédition')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ShippingOrderRepository $repo) {
                        return $repo->createQueryBuilder('so')->orderBy('so.createdAt', 'DESC');
                    },
                    'choice_label' => 'id',
                ]),

            IntegerField::new('index', 'Colis #'),
            NumberField::new('weight', 'Poids (kg)'),
            NumberField::new('length', 'Longueur (cm)'),
            NumberField::new('width', 'Largeur (cm)'),
            NumberField::new('height', 'Hauteur (cm)'),
            NumberField::new('price', 'Prix')->onlyOnIndex(),
            AssociationField::new('shippingLabel', 'Étiquette')->hideOnForm(),
        ];
    }
}