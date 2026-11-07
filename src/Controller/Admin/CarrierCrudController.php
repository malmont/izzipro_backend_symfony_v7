<?php
namespace App\Controller\Admin;

use App\Entity\Carrier;
use App\Form\CarrierTranslationType; // On importe le nouveau FormType
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField; // On importe CollectionField
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CarrierCrudController extends BaseTenantCrudController
{
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return Carrier::class;
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Transporteur')->setEntityLabelInPlural('Transporteurs et retrait')
            ->setHelp('index', 'Les modes de remise proposés à vos clients au paiement : prix fixe (livraison standard, locale…), retrait en magasin (toujours gratuit), ou tarif EasyPost en direct (compte transporteur EasyPost). Vous expédiez vous-même en saisissant le suivi sur la commande.');
    }

    /** Vente au comptoir : retrait = toujours 0 $, et jamais de compte EasyPost (lot 1 Livraison, 07/11/2026) */
    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->normalize($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->normalize($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function normalize($carrier): void
    {
        if (!$carrier instanceof Carrier) {
            return;
        }
        if ($carrier->getKind() === Carrier::KIND_PICKUP) {
            $carrier->setPrice(0.0)->setCarrierAccountId(null);
        } elseif ($carrier->getKind() === Carrier::KIND_EASYPOST && trim((string) $carrier->getCarrierAccountId()) === '') {
            $carrier->setKind(Carrier::KIND_FIXED);
        } elseif ($carrier->getKind() === Carrier::KIND_FIXED && trim((string) $carrier->getCarrierAccountId()) !== '') {
            $carrier->setKind(Carrier::KIND_EASYPOST);
        }
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom affiché au client'),
            TextField::new('description', 'Description'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('kind', 'Nature')
                ->setChoices(['Prix fixe' => Carrier::KIND_FIXED, 'Retrait en magasin (gratuit)' => Carrier::KIND_PICKUP, 'Tarif EasyPost (compte transporteur)' => Carrier::KIND_EASYPOST])
                ->renderAsBadges([Carrier::KIND_FIXED => 'primary', Carrier::KIND_PICKUP => 'success', Carrier::KIND_EASYPOST => 'info'])
                ->setHelp('Retrait : le prix passe à 0 et aucune adresse d\'expédition n\'est demandée. EasyPost : renseignez le compte transporteur ci-dessous, le tarif est calculé au paiement.'),
            TextField::new('carrierAccountId', 'Compte transporteur EasyPost (ca_…)')->setRequired(false)->hideOnIndex(),
            MoneyField::new('price', 'Prix')->setCurrency($this->currency?->code() ?? 'CAD')->setStoredAsCents(true)->setHelp('Ignoré pour le retrait et pour un tarif EasyPost.'),
            TextField::new('estimatedDays', 'Délai affiché (ex. 3 à 7 jours ouvrables)')->setRequired(false),
            ImageField::new('photo')->setBasePath('/bucket-simulator/assets/uploads/Carrier/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/Carrier/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(CarrierTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('name', 'Nom (Défaut)')->onlyOnIndex(),
        ];
    }
}