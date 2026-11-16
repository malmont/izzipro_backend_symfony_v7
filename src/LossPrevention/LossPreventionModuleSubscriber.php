<?php

namespace App\LossPrevention;

use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\FeatureAccess\ModuleRequestHint;
use App\Services\Http\RequestPath;
use App\Services\Platform\PlatformModeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garde du module Prévention des pertes (lot L1-a, 14/11/2026). Ses routes sont sous `/api/gestion/pertes` : la garde commune
 * (`ModuleAccessSubscriber`, priorité 15) exige déjà la gestion (`app.management`) ; celle-ci, juste après (priorité 14), exige EN PLUS le
 * droit `module.loss_prevention`. Même réponse que la garde commune : 403 `MODULE_INACTIVE` avec `module`, `feature` (la clé refusée, toujours
 * présente : les écrans s'en servent pour ne pas fermer toute la gestion), `requestable`, `request`, `trial`. Même règle du mode de la
 * plateforme : en mode « demo », rien n'est refusé. Indicateurs illisibles : la requête passe, la suite de la chaîne répond. Le chemin est
 * lu par `RequestPath::of` (lot CHEMIN), comme toute garde.
 *
 * Mode exercice (lot EX-1, décision du propriétaire) : les routes de `OPEN_ROUTES`, et elles seules, passent SANS le droit du module
 * (démonstration avant achat). La liste est écrite en toutes lettres, méthode et chemin exact : jamais un préfixe. La garde de la gestion
 * (priorité 15) et le rôle d'administrateur leur restent appliqués ; tout le reste de la section reste fermé sans le droit.
 */
class LossPreventionModuleSubscriber implements EventSubscriberInterface
{
    public const CODE = 'MODULE_INACTIVE';
    /** La section de la gestion, et les routes du boîtier de la boutique (lot L3-a) : un boîtier ne parle pas à un site sans le module */
    public const PATH = '#^/api/(gestion/pertes|pertes/boitier)(/|$)#';
    /** Routes du mode exercice, ouvertes sans le droit du module : méthode => chemins exacts */
    public const OPEN_ROUTES = [
        // `\z`, jamais `$` : un « $ » accepte un saut de ligne final (« …/exercices%0A » passerait la garde)
        'GET' => ['#^/api/gestion/pertes/exercices\z#', '#^/api/gestion/pertes/exercices/\d+\z#',
            // lot L2-a : pièces d'exercice et puces simulées
            '#^/api/gestion/pertes/exercices/pieces\z#', '#^/api/gestion/pertes/exercices/pieces/\d+\z#', '#^/api/gestion/pertes/exercices/puces/recherche\z#',
            // lot L2-b : assistant d'installation et gamme de matériel
            '#^/api/gestion/pertes/exercices/installation\z#', '#^/api/gestion/pertes/exercices/materiel\z#',
            // lot L3-a : boîtier simulé
            '#^/api/gestion/pertes/exercices/boitiers\z#', '#^/api/gestion/pertes/exercices/boitiers/\d+\z#', '#^/api/gestion/pertes/exercices/sante\z#'],
        'POST' => ['#^/api/gestion/pertes/exercices\z#',
            '#^/api/gestion/pertes/exercices/pieces\z#', '#^/api/gestion/pertes/exercices/pieces/\d+/retirer\z#',
            '#^/api/gestion/pertes/exercices/puces/associer\z#', '#^/api/gestion/pertes/exercices/puces/\d+/retirer\z#',
            '#^/api/gestion/pertes/exercices/pieces/etiquettes/pdf\z#', '#^/api/gestion/pertes/exercices/installation/etapes/[a-z_]{1,30}/verifier\z#',
            '#^/api/gestion/pertes/exercices/installation/puce-test\z#', '#^/api/gestion/pertes/exercices/installation/etiquette-test\z#',
            '#^/api/gestion/pertes/exercices/boitiers\z#', '#^/api/gestion/pertes/exercices/boitiers/\d+/scenarios\z#'],
        'DELETE' => ['#^/api/gestion/pertes/exercices\z#', '#^/api/gestion/pertes/exercices/pieces\z#', '#^/api/gestion/pertes/exercices/boitiers\z#'],
        'PUT' => ['#^/api/gestion/pertes/exercices/\d+/decision\z#'],
    ];

    public function __construct(private readonly ModuleAccessService $modules, private readonly PlatformModeService $platform, private readonly LoggerInterface $logger,
        private readonly ?ModuleRequestHint $hints = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 14]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // chemin tel que le routeur le lit (décodé) : « /api/gestion/%70ertes » est la même route
        $path = RequestPath::of($event->getRequest());
        if (!$event->isMainRequest() || $event->hasResponse() || !preg_match(self::PATH, $path)) {
            return;
        }
        foreach (self::OPEN_ROUTES[strtoupper($event->getRequest()->getMethod())] ?? [] as $open) {
            if (preg_match($open, $path)) {
                return; // mode exercice : ouvert sans le droit du module
            }
        }
        $module = ModuleAccessService::LOSS_PREVENTION;
        try {
            if ($this->platform->isDemo() || $this->modules->isEnabled($module)) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('LossPreventionModuleSubscriber : indicateurs de modules illisibles, requête laissée passer : ' . $e->getMessage());

            return;
        }
        if (preg_match(LossBoxGuardSubscriber::PATH, $path)) {
            // Routes du boîtier : appelées sans compte, parfois sans jeton (inscription). La réponse ne dit rien de l'offre du site
            // (ni essai, ni demande en cours), et un appel anonyme n'écrit pas au journal
            $event->setResponse(new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'message' => 'Ce site n\'a pas le module du boîtier.'], 403));

            return;
        }
        $this->logger->info(sprintf('Module %s inactif : %s %s refusé', $module, $event->getRequest()->getMethod(), $path));
        $event->setResponse(new JsonResponse([
            'error' => self::CODE,
            'code' => self::CODE,
            'module' => $module,
            'message' => sprintf('Le module %s n\'est pas activé sur ce site.', ModuleAccessService::LABELS[$module]),
            'feature' => ModuleAccessService::FEATURE_KEYS[$module],
        ] + ($this->hints?->for($module) ?? []), 403));
    }
}
