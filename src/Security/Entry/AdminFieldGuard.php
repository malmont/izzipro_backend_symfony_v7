<?php

namespace App\Security\Entry;

use App\Services\Screens\ScreenRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use EasyCorp\Bundle\EasyAdminBundle\Security\AuthorizationChecker;
use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * LES CHAMPS SENSIBLES DANS EASYADMIN (lot AG-3c, 17/11/2026). EasyAdmin demande, champ par champ, la permission de le montrer
 * (`EA_VIEW_FIELD`) avant de bâtir une liste, une fiche ou un formulaire : refusé, le champ n'existe plus pour cette page (ni affiché ni
 * écrit). Pour l'OPÉRATEUR d'une agence entrée, les champs ci-dessous de l'écran courant sont refusés ; tout le reste, et tout autre
 * compte, suit la règle d'EasyAdmin sans changement.
 */
#[AsDecorator(decorates: AuthorizationChecker::class)]
final class AdminFieldGuard implements AuthorizationCheckerInterface
{
    /** Écran du registre => propriétés retirées à l'opérateur (inventaire `ScreenRegistry::SENSITIVE`, et la fiche du site comme dans l'API) */
    public const FIELDS = [
        'admin.catalogue' => ['purchasePrice', 'coefficientMultiplier', 'commande'],
        // l'adresse qui reçoit commandes et demandes, la devise, le fuseau et le découpage des périodes ; les adresses d'alerte ; le mode Stripe
        'admin.entreprise' => ['email', 'currency', 'posTimezone', 'purchasePeriodFrequency', 'formSubmissionsEmail', 'posAlertsEmail', 'posStripeMode', 'password'],
        'admin.vitrine' => ['candidatures'],
        'admin.commandes-reglages' => ['orders', 'payments'],
        'admin.taxes' => ['orderTaxes'],
        'admin.ia' => ['promptExcerpt', 'user'],
    ];

    public function __construct(
        #[AutowireDecorated] private readonly AuthorizationCheckerInterface $inner,
        private readonly Security $security,
        private readonly AdminContextProvider $contexts,
    ) {
    }

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        if ($attribute === Permission::EA_VIEW_FIELD && $subject instanceof FieldDto && $this->security->getUser() instanceof ExternalOperator) {
            $screen = $this->screen();
            // un écran que le registre ne connaît pas : aucun champ (la garde du mode l'a déjà fermé)
            if ($screen === null || in_array($subject->getProperty(), self::FIELDS[$screen] ?? [], true)) {
                return false;
            }
        }

        return $this->inner->isGranted($attribute, $subject);
    }

    private function screen(): ?string
    {
        $crud = $this->contexts->getContext()?->getCrud()?->getControllerFqcn()
            ?? $this->contexts->getContext()?->getRequest()->query->get(EA::CRUD_CONTROLLER_FQCN);

        return is_string($crud) && $crud !== '' ? ScreenRegistry::adminCrud($crud) : null;
    }
}
