<?php

namespace App\LossPrevention;

use App\LossPrevention\Services\LossBoxChannelService;
use App\LossPrevention\Services\LossBoxException;
use App\LossPrevention\Services\LossBoxToken;
use App\Services\Http\RequestPath;
use App\Services\TenantConnectionProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Garde des routes du BOÎTIER (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16). Ces routes sont hors de `/api/gestion` et
 * sans rôle dans `access_control` (le boîtier n'est pas un utilisateur, il n'a pas de compte) : c'est ICI qu'elles se ferment.
 *
 * - **Tout le préfixe `/api/pertes/boitier` est fermé** : sans jeton de boîtier valable (`X-Loss-Box`), 401 ou 403, pour toute route
 *   qui s'y trouve ou s'y trouvera (une adresse qui n'est pas une route reçoit le 404 du routeur, avant tout contrôleur). Le chemin
 *   est lu par `RequestPath::of` (lot CHEMIN), comme toute garde.
 * - **Deux routes seulement s'ouvrent sans jeton vérifié ici**, écrites en toutes lettres (méthode POST, chemin exact) :
 *   l'inscription (elle demande la clé du boîtier) et l'échange de jeton (elle vérifie elle-même un jeton, même expiré). Toutes
 *   deux sont limitées en débit par adresse.
 * - Le boîtier authentifié est posé dans l'attribut `_loss_box` de la requête ; le contrôleur refuse de travailler sans lui (une
 *   garde débranchée ne laisse donc rien passer). Chaque boîtier a son plafond d'appels.
 * - Priorité 13 : après la garde du module (14), qui répond 403 `MODULE_INACTIVE` quand le droit est retiré, avant le pare-feu.
 */
class LossBoxGuardSubscriber implements EventSubscriberInterface
{
    public const PATH = '#^/api/pertes/boitier(/|$)#';
    /** Routes ouvertes sans jeton vérifié par la garde : POST, chemin exact (`\z`, jamais `$`) */
    public const OPEN_ROUTES = ['#^/api/pertes/boitier/inscription\z#', '#^/api/pertes/boitier/jeton\z#'];
    public const ATTRIBUTE = '_loss_box';
    /** Essais d'inscription et d'échange de jeton : par site et adresse, puis par adresse seule (15 minutes) */
    public const OPEN_LIMIT = 20;
    public const OPEN_GLOBAL_LIMIT = 60;
    /** Appels d'un boîtier (5 minutes) : un signe de vie par minute et ses lots de faits tiennent très au large */
    public const BOX_LIMIT = 600;

    private readonly RateLimiterFactory $open;
    private readonly RateLimiterFactory $openGlobal;
    private readonly RateLimiterFactory $calls;

    public function __construct(
        private readonly LossBoxChannelService $channel,
        private readonly TenantConnectionProvider $tenant,
        #[Autowire(service: 'cache.rate_limiter')] CacheItemPoolInterface $pool,
    ) {
        $storage = new CacheStorage($pool);
        $this->open = new RateLimiterFactory(['id' => 'loss_box_open', 'policy' => 'sliding_window', 'limit' => self::OPEN_LIMIT, 'interval' => '15 minutes'], $storage);
        $this->openGlobal = new RateLimiterFactory(['id' => 'loss_box_open_global', 'policy' => 'sliding_window', 'limit' => self::OPEN_GLOBAL_LIMIT, 'interval' => '15 minutes'], $storage);
        $this->calls = new RateLimiterFactory(['id' => 'loss_box_calls', 'policy' => 'sliding_window', 'limit' => self::BOX_LIMIT, 'interval' => '5 minutes'], $storage);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 13]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // chemin tel que le routeur le lit (décodé) : « /api/pertes/%62oitier » est la même route
        $path = RequestPath::of($request);
        if (!$event->isMainRequest() || $event->hasResponse() || !preg_match(self::PATH, $path)) {
            return;
        }
        $site = (string) $this->tenant->getTenantCode();
        if ($request->getMethod() === 'POST' && self::isOpen($path)) {
            $ip = $request->getClientIp() ?? 'unknown';
            foreach ([[$this->open, $site . '|' . $ip], [$this->openGlobal, $ip]] as [$factory, $key]) {
                $limit = $factory->create($key)->consume();
                if (!$limit->isAccepted()) {
                    $event->setResponse(self::tooMany($limit->getRetryAfter()->getTimestamp()));

                    return;
                }
            }

            return; // la route vérifie elle-même la clé ou le jeton
        }
        try {
            $box = $this->channel->authenticate($request->headers->get(LossBoxToken::HEADER));
        } catch (LossBoxException $e) {
            $event->setResponse(new JsonResponse($e->body(), $e->status));

            return;
        }
        $limit = $this->calls->create($site . '|' . $box['id'])->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(self::tooMany($limit->getRetryAfter()->getTimestamp()));

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $box);
    }

    private static function isOpen(string $path): bool
    {
        foreach (self::OPEN_ROUTES as $open) {
            if (preg_match($open, $path)) {
                return true;
            }
        }

        return false;
    }

    private static function tooMany(int $retryAt): JsonResponse
    {
        $retryAfter = max(1, $retryAt - time());

        return new JsonResponse(['error' => 'RATE_LIMITED', 'code' => 'RATE_LIMITED', 'message' => 'Trop d\'appels. Réessayez plus tard.', 'retryAfterSeconds' => $retryAfter], 429,
            ['Retry-After' => (string) $retryAfter]);
    }
}
