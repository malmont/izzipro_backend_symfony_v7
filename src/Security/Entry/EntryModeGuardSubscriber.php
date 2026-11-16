<?php

namespace App\Security\Entry;

use App\Import\ImportJobStore;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Http\RequestPath;
use App\Services\Screens\ScreenRegistry;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * LA GARDE DU MODE AGENCE (lot AG-3b, 16/11/2026, `docs/ecrans-et-contextes.md` § 7). Elle s'applique à l'OPÉRATEUR EXTÉRIEUR
 * (`ExternalOperator`) : un membre d'agence entré dans le site d'un client par un jeton d'entrée. FERMÉE PAR DÉFAUT.
 *
 * 1. La route se classe par le registre des écrans (`ScreenRegistry::classify`, sur le chemin décodé). Elle n'est servie que si son
 *    écran est de la famille CONSTRUCTION et que son module est actif sur le site. Route non rangée, liste hors écran, autre famille,
 *    module éteint : 403 `MODE_FORBIDDEN`.
 * 2. Les LECTURES du site public (`@public`, GET) sont servies comme à un visiteur : l'authentification est retirée, le contrôleur ne
 *    voit aucun compte (sinon les rôles d'administrateur de l'opérateur y ouvriraient ce qu'un administrateur lit : un coût, une
 *    commande). Toute autre liste hors écran est fermée, y compris le compte (`@compte` : un jeton d'entrée n'en obtient pas d'autre).
 * 3. Restent FERMÉS, même s'ils sont de la construction (précisions du chef) : les clés d'API et d'activation, l'export, le paiement
 *    (Stripe), le changement des modules du site (le forfait de l'agence n'y est pas encore relu) ; l'écriture du coût d'achat et du
 *    coefficient d'un article ; l'import des clients et des commandes.
 * 4. La réponse d'un écran de construction perd ses CHAMPS SENSIBLES (`ScreenRegistry::SENSITIVE`, par suffixe du chemin du champ).
 *
 * Après le pare-feu (priorité 8), avec les autres gardes de portée (7). L'entrée du compte de la plateforme (mode super
 * administrateur) n'est pas bornée ici : il garde tous ses écrans de site.
 */
class EntryModeGuardSubscriber implements EventSubscriberInterface
{
    public const CODE = 'MODE_FORBIDDEN';
    public const OPEN = 'open';
    public const PUBLIC = 'public';
    public const CLOSED = 'closed';
    /** L'écran de la requête, posé pour le filtre de la réponse */
    public const ATTRIBUTE = '_entry_screen';
    private const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** Fermés même dans la construction : expression du chemin => [méthodes (vide : toutes), raison] */
    private const CLOSED_PATHS = [
        ['#^/api/app-keys(/|$)#', [], 'keys'],
        ['#(^|/)exports?(/|\.|$)#i', [], 'export'],
        ['#stripe#i', [], 'payment'],
        ['#^/api/site/features(/|$)#', self::WRITE, 'modules'],
    ];
    /** Champs du coût d'un article, jamais écrits par l'opérateur */
    private const COST_FIELDS = ['purchasePrice', 'coefficientMultiplier'];

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ?ImportJobStore $imports = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7], KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    /**
     * La décision pour une route, sans rien lire d'autre que le registre (essayée sur toutes les routes du routeur).
     *
     * @return array{0: string, 1: ?string, 2: ?string} [open|public|closed, cible du registre, raison du refus]
     */
    public static function decision(string $method, string $path): array
    {
        $method = strtoupper($method);
        $target = ScreenRegistry::classify($method, $path);
        if (!str_starts_with($path, '/api/')) {
            return [self::CLOSED, $target, 'surface']; // l'API seulement : EasyAdmin est le lot AG-3c
        }
        if ($target === null) {
            return [self::CLOSED, null, 'unclassified'];
        }
        if (str_starts_with($target, '@')) {
            return $target === '@public' && in_array($method, ['GET', 'HEAD'], true) ? [self::PUBLIC, $target, null] : [self::CLOSED, $target, 'off_screen'];
        }
        if (ScreenRegistry::familyOf($target) !== ScreenRegistry::CONSTRUCTION) {
            return [self::CLOSED, $target, 'family'];
        }
        foreach (self::CLOSED_PATHS as [$pattern, $methods, $reason]) {
            if (preg_match($pattern, $path) === 1 && ($methods === [] || in_array($method, $methods, true))) {
                return [self::CLOSED, $target, $reason];
            }
        }

        return [self::OPEN, $target, null];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tokens->getToken()?->getUser() instanceof ExternalOperator) {
            return;
        }
        $request = $event->getRequest();
        $path = RequestPath::of($request);
        [$decision, $target, $reason] = self::decision($request->getMethod(), $path);
        if ($decision === self::PUBLIC) {
            $this->tokens->setToken(null); // servie EXACTEMENT comme à un visiteur

            return;
        }
        if ($decision === self::OPEN) {
            $reason = $this->moduleOff((string) $target) ?? $this->bodyRefused($request, (string) $target, $path);
        }
        if ($decision !== self::OPEN || $reason !== null) {
            $event->setResponse(new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'reason' => $reason,
                'message' => 'Cette page n\'est pas ouverte à une agence dans le site de son client.'], 403));

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $target);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $target = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        $response = $event->getResponse();
        if (!is_string($target) || !$event->isMainRequest() || !str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return;
        }
        $specs = array_map(static fn (string $field): array => explode('.', str_replace('[]', '.[]', $field)), ScreenRegistry::SENSITIVE[$target]['fields'] ?? []);
        $isImportList = $target === 'imports' && RequestPath::of($event->getRequest()) === '/api/site/imports';
        if ($specs === [] && !$isImportList) {
            return;
        }
        $content = (string) $response->getContent();
        $data = json_decode($content);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            return;
        }
        if ($isImportList && $data instanceof \stdClass && is_array($data->imports ?? null)) {
            // la liste mêle les natures : seuls les imports du catalogue restent
            $data->imports = array_values(array_filter($data->imports, static fn (mixed $job): bool => $job instanceof \stdClass && ($job->kind ?? null) === ImportJobStore::KIND_CATALOG));
        }
        $response->setContent((string) json_encode(self::strip($data, $specs), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Retire d'une réponse décodée (objets en `stdClass`) les champs dont le chemin se TERMINE par l'un des chemins donnés ; `[]` y
     * désigne un élément de liste (`steps[].description` : la description de chaque étape).
     *
     * @param list<list<string>> $specs
     * @param list<string> $path
     */
    public static function strip(mixed $node, array $specs, array $path = []): mixed
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                $here = [...$path, (string) $key];
                if (self::matches($here, $specs)) {
                    unset($node->{$key});
                    continue;
                }
                $node->{$key} = self::strip($value, $specs, $here);
            }

            return $node;
        }
        if (is_array($node)) {
            foreach ($node as $index => $value) {
                $node[$index] = self::strip($value, $specs, [...$path, '[]']);
            }
        }

        return $node;
    }

    /** @param list<string> $path @param list<list<string>> $specs */
    private static function matches(array $path, array $specs): bool
    {
        foreach ($specs as $spec) {
            if (count($spec) <= count($path) && array_slice($path, -count($spec)) === $spec) {
                return true;
            }
        }

        return false;
    }

    /** Le module de l'écran est-il éteint sur ce site ? Un module éteint chez le client est fermé en entrée. */
    private function moduleOff(string $target): ?string
    {
        $module = ScreenRegistry::screen($target)['module'] ?? null;
        if ($module === null) {
            return null;
        }
        $column = FeatureCatalog::GOVERNED[$module] ?? null;
        if ($column === null) {
            return 'module';
        }
        $value = $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT ' . $column . ' FROM entreprise ORDER BY id LIMIT 1');

        return in_array($value, [true, 't', 'true', 1, '1'], true) ? null : 'module';
    }

    /** Ce que le corps ou l'objet de la requête ferme : le coût d'un article, un import de clients ou de commandes */
    private function bodyRefused(Request $request, string $target, string $path): ?string
    {
        if ($target === 'catalogue' && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            if ((is_array($body) && self::carriesCost($body)) || array_intersect(self::COST_FIELDS, array_keys($request->request->all())) !== []) {
                return 'cost';
            }
        }
        if ($target === 'imports') {
            if ($path === '/api/site/imports' && $request->getMethod() === 'POST') {
                return (string) $request->request->get('kind', ImportJobStore::KIND_CATALOG) === ImportJobStore::KIND_CATALOG ? null : 'kind';
            }
            if (preg_match('#^/api/site/imports/(\d+)(/|$)#', $path, $m) === 1) {
                $job = $this->imports?->find((int) $m[1]);

                return $job !== null && ($job['kind'] ?? null) !== ImportJobStore::KIND_CATALOG ? 'kind' : null;
            }
        }

        return null;
    }

    /** @param array<mixed> $body */
    private static function carriesCost(array $body): bool
    {
        foreach ($body as $key => $value) {
            if (in_array($key, self::COST_FIELDS, true) || (is_array($value) && self::carriesCost($value))) {
                return true;
            }
        }

        return false;
    }
}
