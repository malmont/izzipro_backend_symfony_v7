<?php

namespace App\EventSubscriber;

use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Devise du site dans les réponses de gestion à racine objet (lot G1, 09/11/2026, `docs/gestion-mise-a-niveau.md` § 7) :
 * tableaux de bord, statistiques, frais, taxes, valeur et évolution du stock reçoivent `currency` (ISO 4217) à la racine,
 * sans toucher aux clés existantes. Les listes (collections, commandes, notes de frais, frais de port) portent `currency`
 * sur chaque élément (DTO). Code ISO lu une fois par requête ; les réponses en cache applicatif passent aussi par ici.
 */
class GestionCurrencyResponseSubscriber implements EventSubscriberInterface
{
    /** Routes dont la réponse 200 est un objet JSON portant des montants */
    public const ROUTES = [
        'dashboard_commande', 'dashboard_collection', 'dashboard_collections_combined', 'close_command', 'close_collection',
        'statistiques_chiffre_affaires', 'statistiques_nombre_commandes', 'statistiques_panier_moyen', 'get_payments_statistics', 'frais_total', 'get_monthly_taxes', 'get_carrier_statistics',
        'stock_value_all', 'stock_evolution_all',
        'gestion_campagnes_rentabilite', // rentabilité perçue d'une campagne (lot A1, 10/11/2026)
        'gestion_reprise_simulation', 'gestion_reprise_appliquer', 'gestion_reprise_annuler', 'gestion_reprise_etat', // reprise d'historique (lot P3, demande de l'application du 10/11/2026)
    ];

    public function __construct(private readonly TenantCurrencyProvider $currency)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -8]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$response instanceof JsonResponse || $response->getStatusCode() !== 200
            || !in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }
        $data = json_decode((string) $response->getContent(), true);
        if (!is_array($data) || array_is_list($data) || isset($data['currency'])) {
            return;
        }
        $data['currency'] = $this->currency->code();
        $response->setData($data);
    }
}
