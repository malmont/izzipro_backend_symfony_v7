<?php

namespace App\Controller\Admin;

use App\Entity\StripeConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Controller\Admin\BaseTenantCrudController;

class StripeConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return StripeConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuration Stripe')
            ->setEntityLabelInPlural('Configuration Stripe')
            ->setPageTitle(Crud::PAGE_INDEX, 'Passerelle de Paiement Stripe')
            ->setHelp(Crud::PAGE_INDEX, 'Connectez ou gérez votre compte Stripe Connect pour encaisser les paiements par carte bancaire de vos clients.<br><br>💡 <strong>Important pour votre compte bancaire :</strong> Le pays et l\'adresse de votre compte Stripe sont automatiquement configurés à partir du menu <strong>Adresses Entreprises</strong>. Si vous êtes établi au <strong>Canada</strong> ou en <strong>France</strong>, assurez-vous d\'avoir renseigné votre adresse d\'entreprise avant de cliquer sur Connecter. Sans adresse configurée, le compte sera initialisé par défaut aux <strong>États-Unis</strong>.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $connectStripe = Action::new('connectStripe', 'Connecter / Configurer Stripe', 'fab fa-stripe')
            ->linkToRoute('admin_stripe_connect')
            ->createAsGlobalAction()
            ->setCssClass('btn btn-primary');

        $disconnectStripe = Action::new('disconnectStripe', 'Déconnecter', 'fas fa-unlink')
            ->linkToUrl(fn () => $this->generateUrl('admin_stripe_disconnect', [
                '_token' => $this->container->get('security.csrf.token_manager')->getToken('stripe_disconnect')->getValue(),
            ]))
            ->setCssClass('text-danger')
            ->displayIf(fn(StripeConfig $config) => (bool) $config->getAccountId());

        return $actions
            ->add(Crud::PAGE_INDEX, $connectStripe)
            ->add(Crud::PAGE_INDEX, $disconnectStripe)
            ->disable(Action::NEW); // Empêche la création manuelle d'un compte sans passer par l'onboarding Stripe
    }

    public function configureFields(string $pageName): iterable
    {
        $isSuperAdmin = $this->isGranted('ROLE_SUPER_ADMIN');
        $accountIdField = TextField::new('accountId', 'Identifiant Stripe Connect (Account ID)')
            ->setHelp('Identifiant du compte Express connecté sur votre plateforme Stripe.' . (!$isSuperAdmin ? ' Modifiable uniquement par le super administrateur.' : ''));
        $onlineFeeField = \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('onlineFeeBp', 'Commission plateforme en ligne (centièmes de %)')
            ->setHelp('100 = 1 %, valeur par défaut : aucun changement tant qu\'elle n\'est pas modifiée. Les paiements en magasin ont leurs propres taux (fiche entreprise).' . (!$isSuperAdmin ? ' Modifiable uniquement par le super administrateur.' : ''))
            ->hideOnIndex();

        if (!$isSuperAdmin) {
            $accountIdField
                ->setRequired(false)
                ->setFormTypeOption('attr', ['disabled' => 'disabled', 'readonly' => 'readonly']);
            $onlineFeeField
                ->setRequired(false)
                ->setFormTypeOption('empty_data', '100')
                ->setFormTypeOption('attr', ['disabled' => 'disabled', 'readonly' => 'readonly']);
        }

        return [
            IdField::new('id', 'ID')->hideOnForm(),
            $accountIdField,
            BooleanField::new('isActive', 'Paiements Actifs')
                ->renderAsSwitch(false)
                ->setHelp('Indique si les paiements par carte bancaire sont autorisés et fonctionnels.'),
            $onlineFeeField,
        ];
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StripeConfig) {
            $this->enforceSuperAdminFields($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof StripeConfig) {
            $this->enforceSuperAdminFields($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    /** Réimpose account_id et online_fee_bp lus en base pour un administrateur de site */
    private function enforceSuperAdminFields(StripeConfig $entity): void
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $id = (int) $entity->getId();
        if ($id <= 0) {
            $entity->setAccountId(null)->setOnlineFeeBp(100);

            return;
        }

        try {
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT account_id, online_fee_bp FROM stripe_config WHERE id = ?',
                [$id]
            );
        } catch (\Throwable) {
            throw $this->createAccessDeniedException('Réglages réservés au super administrateur.');
        }
        if (!is_array($row)) {
            throw $this->createAccessDeniedException('Réglages réservés au super administrateur.');
        }

        $entity
            ->setAccountId($row['account_id'] !== null ? (string) $row['account_id'] : null)
            ->setOnlineFeeBp((int) $row['online_fee_bp']);
    }
}
