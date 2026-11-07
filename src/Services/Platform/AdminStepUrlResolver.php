<?php

namespace App\Services\Platform;

use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Écrans du back-office visés par le cheminement et l'assistant (cheminement complet, 07/11/2026) : une seule table clé d'écran →
 * CRUD EasyAdmin ou route habillée, partagée par la page d'accueil, « Mon site : pilotage » et l'assistant. Les liens sont habillés
 * (`/admin?routeName=…`) pour garder le menu et le thème.
 */
class AdminStepUrlResolver
{
    public const SCREENS = [
        'entreprise' => ['crud' => \App\Controller\Admin\EntrepriseCrudController::class], 'address_entreprise' => ['crud' => \App\Controller\Admin\AddressEntrepriseCrudController::class],
        'social_networks' => ['crud' => \App\Controller\Admin\SocialNetworkCrudController::class], 'app_keys' => ['route' => 'admin_app_keys'], 'app_profiles' => ['crud' => \App\Controller\Admin\AppProfileCrudController::class],
        'boutique_settings' => ['crud' => \App\Controller\Admin\BoutiqueSettingCrudController::class], 'mon_site' => ['route' => 'admin_mon_site'], 'configuration' => ['route' => 'admin_configuration'],
        'subscription_plans' => ['crud' => \App\Controller\Admin\SubscriptionPlanCrudController::class], 'taxes' => ['crud' => \App\Controller\Admin\TaxCrudController::class], 'stripe_config' => ['crud' => \App\Controller\Admin\StripeConfigCrudController::class],
        'pos_stripe' => ['route' => 'admin_pos_stripe'], 'shipping' => ['route' => 'admin_livraison'], 'product_shipping' => ['crud' => \App\Controller\Admin\ProductShippingCrudController::class], 'product_weights' => ['route' => 'admin_livraison_poids'],
        'easypost' => ['crud' => \App\Controller\Admin\EasyPostConfigurationCrudController::class], 'google_places' => ['crud' => \App\Controller\Admin\GooglePlacesConfigCrudController::class],
        'email_configuration' => ['crud' => \App\Controller\Admin\EmailConfigurationCrudController::class], 'ai_credits' => ['crud' => \App\Controller\Admin\AiCreditSettingCrudController::class], 'mv_ai_quotas' => ['route' => 'admin_mv_ai_quotas'],
        'review_settings' => ['crud' => \App\Controller\Admin\ReviewSettingCrudController::class], 'reviews' => ['crud' => \App\Controller\Admin\ReviewCrudController::class], 'orders' => ['route' => 'admin_orders_todo'],
        'carriers' => ['crud' => \App\Controller\Admin\CarrierCrudController::class], 'products' => ['crud' => \App\Controller\Admin\ProductCrudController::class], 'categories' => ['crud' => \App\Controller\Admin\CategoriesCrudController::class],
        'booking_configuration' => ['crud' => \App\Controller\Admin\BookingConfigurationCrudController::class], 'rental_packs' => ['crud' => \App\Controller\Admin\RentalPackCrudController::class],
        'cash_registers' => ['crud' => \App\Controller\Admin\CashRegisterCrudController::class], 'users' => ['crud' => \App\Controller\Admin\UserCrudController::class], 'currencies' => ['crud' => \App\Controller\Admin\CurrencyCrudController::class],
        'esg_questions' => ['crud' => \App\Controller\Admin\ESG\DiagnosticQuestionCrudController::class], 'short_code' => ['route' => 'admin_short_code'],
        'product_photo' => ['route' => 'admin_product_photo'], 'product_ai_bulk' => ['route' => 'admin_product_ai_bulk'], 'rental' => ['route' => 'admin_location'], 'bookings' => ['crud' => \App\Controller\Admin\BookingCrudController::class], 'pos' => ['route' => 'admin_caisse'], 'denominations' => ['crud' => \App\Controller\Admin\TypeCashCrudController::class], // cheminement Caisse (07/11/2026)
    ];

    public function __construct(private readonly AdminUrlGenerator $urls, private readonly UrlGeneratorInterface $router)
    {
    }

    public function screenUrl(?string $key): ?string
    {
        $screen = self::SCREENS[$key ?? ''] ?? null;
        if ($screen === null) {
            return null;
        }
        try {
            return isset($screen['crud']) ? $this->urls->unsetAll()->setController($screen['crud'])->setAction('index')->generateUrl() : $this->routeUrl($screen['route']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** URL habillée d'une route du back-office */
    public function routeUrl(string $route, array $params = []): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, $params)->generateUrl();
        } catch (\Throwable) {
            return $this->router->generate($route, $params);
        }
    }

    /** Lien d'une étape du cheminement (`SiteReadinessService`) : écran, éditeur, boutique de forfaits, domaine ou registre de Mon site */
    public function stepUrl(array $step): ?string
    {
        $action = $step['action'] ?? [];

        return match ($action['type'] ?? '') {
            'domain' => $this->routeUrl('admin_mon_site') . '#domaine',
            'screen' => $this->screenUrl($action['screen'] ?? null),
            'editor', 'shop' => $action['url'] ?? null,
            'registry' => $this->routeUrl('admin_mon_site') . '#localisation',
            'trial', 'request' => $this->routeUrl('admin_mon_site') . '#fonctionnalites',
            default => null,
        };
    }
}
