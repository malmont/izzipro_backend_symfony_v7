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
        'admin.catalogue' => ['purchasePrice', 'coefficientMultiplier', 'commande', 'commandeCostCents', 'commandeQuantity', 'commandeAttachedAt', 'commandeCostEstimated'],
        // la fiche du site : la liste PARTAGÉE avec l'API (`SiteSheetClosedFields`, voir hidden()) ; ici seulement le mot de passe du serveur d'envoi
        'admin.entreprise' => ['password'],
        'admin.vitrine' => ['candidatures'],
        'admin.commandes-reglages' => ['orders', 'payments'],
        'admin.taxes' => ['orderTaxes'],
        // relecture d'AG-3c : le résumé de la réponse (commandes, clients, chiffres), son issue et la clé de mémoire
        'admin.ia' => ['promptExcerpt', 'user', 'summary', 'outcome', 'memoryKey'],
    ];

    /** Le champ (ou `association.champ`) est-il fermé à l'opérateur sur cet écran ? Sert à l'affichage, à la recherche et au tri */
    public static function hidden(?string $screen, string $property): bool
    {
        if ($screen === null) {
            return true;
        }
        $head = explode('.', $property)[0];
        if (in_array($head, self::FIELDS[$screen] ?? [], true)) {
            return true;
        }
        // la fiche du site : la même liste que `PUT /api/entreprise` (décision du chef : une seule liste pour les deux chemins)
        return $screen === 'admin.entreprise' && SiteSheetClosedFields::closed($head);
    }

    public function __construct(
        #[AutowireDecorated] private readonly AuthorizationCheckerInterface $inner,
        private readonly Security $security,
        private readonly AdminContextProvider $contexts,
    ) {
    }

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        // lot G-1, relecture neuve (passe 2) : le directeur d'un groupe ne voit ni ne règle le terminal de paiement Stripe ni la devise de la fiche
        if ($attribute === Permission::EA_VIEW_FIELD && $subject instanceof FieldDto && $this->security->getUser() instanceof GroupOperator
            && preg_match(EntryModeGuardSubscriber::GROUP_SHEET_CLOSED, (string) $subject->getProperty()) === 1) {
            return false;
        }
        if ($attribute === Permission::EA_VIEW_FIELD && $subject instanceof FieldDto && $this->security->getUser() instanceof ExternalOperator) {
            $screen = $this->screen();
            // un écran que le registre ne connaît pas : aucun champ (la garde du mode l'a déjà fermé)
            if (self::hidden($screen, $subject->getProperty())) {
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
