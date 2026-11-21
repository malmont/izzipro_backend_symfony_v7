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
use Symfony\Component\HttpFoundation\Response;
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
    /** Lot AG-ASSIST : l'assistance qui couvre la requête [numéro, section, méthode], posée pour la trace de la réponse */
    public const ASSISTANCE_ATTRIBUTE = '_entry_assistance';
    /** Toujours fermés pendant une assistance, en lecture comme en écriture : les comptes et leurs rôles (les clés, l'export, Stripe et les
     *  modules sont déjà dans CLOSED_PATHS) */
    private const ASSISTANCE_CLOSED = ['#(^|/)(users?|accounts?|comptes?|roles?|team-roles?)(/|$)#i',
        // des exports de fait : la liste des adresses des abonnés, le fichier entier d'un rapport
        '#^/api/newsletter(/|$)#', '#/fichier(/|$)#'];
    private const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** Fermés même dans la construction : expression du chemin => [méthodes (vide : toutes), raison] */
    private const CLOSED_PATHS = [
        // lot ABO-CONF (décision du chef, 19/11/2026) : l'agence crée et configure les formules de son client, mais n'en supprime jamais une
        ['#^/api/site/abonnements/formules/[^/]+$#', ['DELETE'], 'delete'],
        ['#^/api/app-keys(/|$)#', [], 'keys'],
        ['#(^|/)exports?(/|\.|$)#i', [], 'export'],
        ['#stripe#i', [], 'payment'],
        // les gestes de module (demander, essayer, allumer, éteindre) ; `PUT /api/site/features` se filtre par ses clés (bodyRefused)
        ['#^/api/site/features/[^/]+/(request|trial|enable|disable)$#', [], 'modules'],
        // lot AG-3c : pages d'EasyAdmin dont l'équivalent de l'API est fermé ou filtré pour l'agence. « Mon site » montre les chiffres
        // des étapes de la gestion et porte les gestes de module et les réglages sans filtre de clé ; « Configuration » compte les paiements
        // par carte et envoie un courriel d'essai par le serveur d'envoi ; « Accès de la plateforme » est le journal des accès du site
        ['#^/admin/(mon-site|configuration)(/|$)#', [], 'figures'],
        ['#^/admin/acces-plateforme(/|$)#', [], 'journal'],
    ];
    /** Lot G-1b : posé sur une requête du directeur d'un groupe servie, pour la trace de son écriture */
    public const GROUP_ATTRIBUTE = '_entry_group';
    /**
     * Lot G-1b : fermés au DIRECTEUR d'un groupe (`GroupOperator`), qui a sinon tout le site (décision du propriétaire du 18/11/2026 : sans
     * Stripe ni remboursement, et pas le compte). Les gestes d'argent d'une commande (annuler, capturer, retour) remboursent ou encaissent
     * par Stripe ; les gestes de module changent ce que la boutique paie. Expression du chemin => [méthodes (vide : toutes), raison].
     */
    // (relecture neuve de G-1 : les motifs se ferment par préfixe, jamais sur `\d+$` : un identifiant mal formé `5%20` était converti en 5
    // par PHP et passait à côté de la règle)
    private const GROUP_CLOSED = [
        ['#stripe#i', [], 'payment'],
        ['#^/api/pos/terminal(/|$)#', [], 'payment'],
        ['#(^|/)(refunds?|rembourser|remboursements?)(/|$)#i', [], 'refund'],
        ['#^/admin/orders/[^/]+/(annuler|capturer|retour)(/|$)#', [], 'refund'],
        ['#^/api/order/cancel(/|$)#', [], 'refund'],
        ['#^/api/pos/(returns|exchanges)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'refund'],
        ['#^/api/pos/rentals/deposits/[^/]+/return(/|$)#', [], 'refund'],
        // les cartes-cadeaux : un remboursement en espèces, une annulation ou un ajustement rendent ou changent de l'argent
        ['#^/api/pos/gift-cards/[^/]+/(cash-out|cancel|adjust)(/|$)#', [], 'refund'],
        // lot ABO-1 : la pause, la reprise et la résiliation d'un abonnement passent par Stripe ; des droits offerts donnent de la valeur
        // sans paiement. « Utiliser » un droit (et son annulation) reste un geste ordinaire de la boutique.
        ['#^/api/site/abonnements/[^/]+/(pause|reprise|resiliation|droits)(/|$)#', [], 'payment'],
        // lot ABO-CONF : créer, configurer, archiver ou supprimer une formule (son prix) et ses prestations : geste d'argent, fermé au directeur ;
        // l'aperçu, qui n'écrit rien, reste ouvert
        ['#^/api/site/abonnements/(formules(/(?!apercu$)[^/]+.*)?|prestations(/.*)?)$#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'payment'],
        // lot FIS-1 (décision du chef, 19/11/2026) : les prêts sont en LECTURE SEULE pour le directeur (créer, modifier, supprimer un prêt
        // sont des écritures des comptes de la boutique) ; l'aperçu, qui n'écrit rien, reste ouvert
        ['#^/api/gestion/prets(/(?!apercu$)[^/]+.*)?$#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'payment'],
        // lot FIS-2 (décision du chef) : les aides de même, en LECTURE SEULE pour le directeur (une avance crée un prêt)
        ['#^/api/gestion/aides(/.*)?$#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'payment'],
        ['#^/api/site/features/.+/(request|trial|enable|disable)(/|$)#', [], 'modules'],
        ['#^/api/site/features/?$#', ['PUT', 'PATCH', 'POST'], 'modules'],
        // les mêmes gestes de module dans EasyAdmin (« Mon site »)
        ['#^/admin/mon-site/(demander|essayer|module|regler)(/|$)#', [], 'modules'],
        // les comptes et leurs rôles : le directeur ne se fait jamais un compte qui survivrait à son entrée (comme pendant une assistance)
        ['#(^|/)(users?|accounts?|comptes?|roles?|team-roles?)(/|$)#i', [], 'accounts'],
        ['#^/api/support/agents(/|$)#', [], 'accounts'],
        ['#^/admin/memoires(/|$)#', [], 'memoires'],
        // relecture neuve, passe 2 : l'assistant IA du site confirme des remboursements et des gestes de module ; les clés des applications
        // (activer ou révoquer une caisse) ; le domaine personnalisé (l'adresse de la boutique)
        ['#^/api/site/assistant(/|$)#', [], 'assistant'],
        ['#^/admin/mon-site/assistant(/|$)#', [], 'assistant'],
        ['#^/api/app-keys(/|$)#', [], 'security'],
        ['#^/api/site/domain(/|$)#', [], 'security'],
        ['#^/admin/mon-site/domaine(/|$)#', [], 'security'],
        // garde-fou du chef (toutes les écritures classées une à une, `GroupWritesClassificationTest`) : ce que la liste noire laissait encore
        // la plateforme et les agences (déjà refusées par leur rôle : fermées ici aussi, en clair)
        ['#^/(api/platform|admin/(plateforme|super-admin|redis|workers|technique|short-code))(/|$)#', [], 'platform'],
        ['#^/api/admin-settings/presets(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'platform'],
        ['#^/api/agency(/|$)#', [], 'rights'],
        // la caisse (tiroir, fonds, ventes, cartes-cadeaux, cautions, NIP, clients et fidélité) : gestes d'un caissier, jamais du directeur
        ['#^/api/(caisse|pos)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'cash'],
        ['#^/api/cash-registers(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'payment'],
        // les achats d'un client (commande, panier) et l'achat d'une étiquette de transport (crédit de livraison)
        ['#^/api/(order|cart)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'payment'],
        ['#^/api/shipping/buy(/|$)#', [], 'payment'],
        ['#^/admin/orders/[^/]+/etiquette(/|$)#', [], 'payment'],
        // les droits : demander l'assistance d'une agence, le groupe lui-même, la vérification des taxes, l'adhésion et l'activation des applications
        ['#^/(api/site|admin)/assistance(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'rights'],
        ['#^/api/(group|site/group-invitations|site/group-same-company|site/group-transfers|site/taxes)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'rights'], // lot G-1d : jamais confirmé par le directeur entré ; lot G-5 : jamais envoyé par lui
        // lot G-4 : les outils du groupe (copie du catalogue…) ne se lancent que du siège, jamais par le directeur entré dans une boutique
        ['#^/api/gestion/groupe(/|$)#', [], 'rights'],
        ['#^/api/site/reservations-boutique/[^/]+/(confirmer|annuler)(/|$)#', [], 'rights'], // lot CMD-WEB-1 : le directeur lit les rendez-vous, il ne les confirme ni ne les annule
        // lot ABO-3a (décision du chef, 20/11/2026) : le directeur lit « À faire aujourd'hui », les contestations et les preuves ; il ne marque
        // rien (fait, absent, séance entière), ne corrige ni ne tranche (une contestation, un courriel à l'abonné, un droit rendu)
        ['#^/api/site/abonnements/([^/]+/occurrences|creneaux/[^/]+/fait)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'rights'],
        // lot ABO-3b (contrat validé par le chef, 20/11/2026) : le fil de l'abonné ni lu ni écrit par le directeur (les messages des clients) ;
        // les intervenants (des comptes de la boutique, comme les agents de l'Assistance) ni lus, ni invités, ni retirés par lui
        ['#^/api/site/abonnements/([^/]+/fil|fils)(/|$)#', [], 'customers'],
        ['#^/api/site/abonnements/intervenants(/|$)#', [], 'accounts'],
        // ... et les routes de l'intervenant, que son rôle ne porte de toute façon pas (fermées ici aussi, par prudence)
        ['#^/api/staff/abonnements(/|$)#', [], 'rights'],
        // lot ABO-4 : le directeur lit la liste d'attente d'un créneau (comme l'agenda) ; il n'offre pas de place (un courriel à l'abonné)
        ['#^/api/site/abonnements/attente/[^/]+/proposer(/|$)#', [], 'rights'],
        ['#^/admin/taxes/verifier(/|$)#', ['POST'], 'rights'],
        ['#^/api/(apps/join|app/activate)(/|$)#', [], 'keys'],
        // le boîtier réel de la prévention des pertes (jumelage, clé, révocation, appareils) ; l'exercice simulé reste ouvert
        ['#^/api/gestion/pertes/boitiers(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'keys'],
        // les appels des machines (boîtier, webhooks signés) et les autres applications (Boussole ESG)
        ['#^/api/(pertes/boitier|webhooks)(/|$)#', [], 'technical'],
        ['#^/api/boussole(/|$)#', [], 'app'],
        // décisions du chef (18/11/2026) : les outils d'IA (unités de la BOUTIQUE ; débit au siège dans un lot suivant) — fermés aussi au point
        // unique de réservation des unités (`LandingAiQuotaService::reserve`) — et l'import des clients finaux et leurs invitations
        ['#^/api/gestion/(console|conseils/actualiser|preparation/avis|statistiques/annee/avis)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'ai'],
        ['#^/api/gestion/conversations/[^/]+/messages(/|$)#', [], 'ai'],
        ['#^/api/gestion/documents(/[^/]+/relire)?/?$#', ['POST'], 'ai'],
        ['#^/api/gestion/pertes/(constats|exercices)/[^/]+/avis(/|$)#', [], 'ai'],
        ['#^/api/periodes/[^/]+/simulation/commentaire(/|$)#', [], 'ai'],
        ['#^/api/(products/ai|landingpage-ai)(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'ai'],
        ['#^/admin/produits/(depuis-photo|ia-en-lot|[^/]+/ia)(/|$)#', [], 'ai'],
        ['#^/api/site/imports(/|$)#', ['POST', 'PUT', 'PATCH', 'DELETE'], 'customers'],
        // Mémoires Vivantes : un projet à part (décision du propriétaire du 16/11/2026), outil de l'équipe et de ses clients, jamais du
        // directeur d'un groupe (ses routes attendent un compte du site : trouvé par la sonde de G-1b)
        ['#^/api/memoires(/|$)#', [], 'memoires'],
    ];
    /** Champs du coût d'un article, jamais écrits par l'opérateur (le coût d'achat, et le coût de la commande fournisseur que lisent la
     *  marge, la valeur du stock et le résultat de période) */
    private const COST_FIELDS = ['purchasePrice', 'coefficientMultiplier', 'commande', 'commandeCostCents', 'commandeQuantity', 'commandeAttachedAt', 'commandeCostEstimated'];
    /** Champs de la fiche du site fermés à l'opérateur : l'adresse qui reçoit les commandes, les demandes et les réservations des
     *  clients ; la devise, le fuseau et la fréquence des périodes, qui changent le sens des montants enregistrés. Les TAXES (prix taxes
     *  comprises) restent ouvertes à l'agence : décision du propriétaire du 15/11/2026, le commerçant confirme « Taxes vérifiées » (AG-3d) */
    // (lot AG-3c, décision du chef : la liste est PARTAGÉE avec EasyAdmin, `SiteSheetClosedFields` ; l'onglet Caisse et les réglages hors
    // construction de la fiche s'y ajoutent)

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntryContext $entry,
        private readonly ?ImportJobStore $imports = null,
        private readonly ?\App\Services\TenantConnectionProvider $tenants = null,
        private readonly ?\App\Services\Agency\Assistance\AssistanceService $assistance = null,
        // lot AG-3c : la route nommée d'une requête d'EasyAdmin (`routeName`), et la page HTML d'un refus
        private readonly ?\Symfony\Component\Routing\RouterInterface $router = null,
        private readonly ?\Twig\Environment $twig = null,
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
    public static function decision(string $method, string $path, ?string $adminTarget = null): array
    {
        $method = strtoupper($method);
        // lot AG-3c : EasyAdmin suit la même règle que l'API ; une requête sur `/admin` se classe par son écran ou sa route (adminTarget)
        $admin = self::isAdmin($path);
        $target = $admin && $adminTarget !== null ? $adminTarget : ScreenRegistry::classify($method, $path);
        if (!str_starts_with($path, '/api/') && !$admin) {
            return [self::CLOSED, $target, 'surface'];
        }
        if ($target === null || $target === '?') {
            return [self::CLOSED, null, 'unclassified'];
        }
        if (str_starts_with($target, '@')) {
            return $target === '@public' && in_array($method, ['GET', 'HEAD'], true) && !$admin ? [self::PUBLIC, $target, null] : [self::CLOSED, $target, 'off_screen'];
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

    /**
     * Lot G-1b : la décision pour le DIRECTEUR d'un groupe : null = servie ; sinon la raison du refus. Tout le site est ouvert, sauf le compte
     * (`@compte` : un jeton d'entrée n'en obtient pas d'autre), les écrans du paiement et GROUP_CLOSED.
     */
    public static function groupDecision(string $method, string $path, ?string $adminTarget = null): ?string
    {
        $method = strtoupper($method);
        $target = self::isAdmin($path) && $adminTarget !== null ? $adminTarget : ScreenRegistry::classify($method, $path);
        if ($target === '@compte') {
            return 'account';
        }
        if ($target === 'admin.paiement') {
            return 'payment';
        }
        // relecture neuve de G-1 : les écrans d'EasyAdmin se jugent aussi par leur code (le chemin d'un écran CRUD est `/admin`)
        $closedScreens = ['admin.utilisateurs' => 'accounts', 'admin.memoires' => 'memoires', 'admin.abonnements' => 'payment'];
        if (is_string($target) && isset($closedScreens[$target])) {
            return $closedScreens[$target];
        }
        // les réglages sensibles (serveur d'envoi des courriels et son mot de passe, clés des applications, devise, unités IA)
        if (is_string($target) && !str_starts_with($target, '@') && ScreenRegistry::familyOf($target) === ScreenRegistry::SECURITE) {
            return 'security';
        }
        foreach (self::GROUP_CLOSED as [$pattern, $methods, $reason]) {
            if (preg_match($pattern, $path) === 1 && ($methods === [] || in_array($method, $methods, true))) {
                return $reason;
            }
        }

        return null;
    }

    /** Lot AG-3c : une page d'EasyAdmin (`/admin` et ses pages à adresse propre) */
    public static function isAdmin(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    /** Lot AG-3c : les seules sorties de l'opérateur d'une entrée d'EasyAdmin hors des écrans : quitter l'entrée, se déconnecter */
    private const ADMIN_EXITS = ['/admin/entree/quitter', '/logout'];

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof GroupOperator) {
            $this->groupRequest($event, $request);

            return;
        }
        if (!$user instanceof ExternalOperator) {
            // l'entrée du compte de la plateforme garde tous ses écrans de site, mais n'écrit rien sur un compte (profil, adresse, mot
            // de passe : une telle écriture rendrait un jeton ordinaire)
            // (relecture d'AG-3c : « Quitter » et la déconnexion restent permis, sinon l'entrée du super administrateur ne se fermait pas)
            if ($this->entry->active() && !in_array($request->getMethod(), ['GET', 'HEAD'], true) && !in_array(RequestPath::of($request), self::ADMIN_EXITS, true)
                && ScreenRegistry::classify($request->getMethod(), RequestPath::of($request)) === '@compte') {
                $event->setResponse($this->refusal('account'));
            }

            return;
        }
        $path = RequestPath::of($request);
        if (in_array($path, self::ADMIN_EXITS, true)) {
            return;
        }
        $adminTarget = null;
        if ($path === '/admin') {
            // le chemin /admin sert tous les écrans d'EasyAdmin : écran CRUD (crudControllerFqcn) ou page nommée (routeName). Relecture
            // d'AG-3c : EasyAdmin exécute `routeName` même quand `crudControllerFqcn` est là, et une page nommée se juge sur SON chemin
            [$adminPath, $adminTarget, $refused] = $this->adminRequest($request);
            if ($refused !== null) {
                $event->setResponse($this->adminRefusal($refused));

                return;
            }
            $path = $adminPath;
        }
        [$decision, $target, $reason] = self::decision($request->getMethod(), $path, $adminTarget);
        if ($decision === self::PUBLIC) {
            $this->tokens->setToken(null); // servie EXACTEMENT comme à un visiteur

            return;
        }
        if ($decision === self::OPEN) {
            $reason = $this->moduleOff((string) $target) ?? $this->bodyRefused($request, (string) $target, $path) ?? $this->storefrontRefused($request, $path, $user);
        } elseif ($reason === 'family' && is_string($target)) {
            // lot AG-ASSIST : une section fermée à l'agence, ouverte par le COMMERÇANT pour une heure
            $reason = $this->assistanceRefused($request, $target, $path, $user);
            if ($reason === null) {
                return;
            }
        }
        if ($decision !== self::OPEN || $reason !== null) {
            $event->setResponse(self::isAdmin($path) ? $this->adminRefusal($reason) : $this->refusal($reason));

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $target);
    }

    /** Lot G-1b : la requête du directeur d'un groupe ; une requête `/admin` se juge sur ce qu'EasyAdmin va exécuter, comme pour l'agence */
    private function groupRequest(RequestEvent $event, Request $request): void
    {
        $path = RequestPath::of($request);
        if (in_array($path, self::ADMIN_EXITS, true)) {
            return;
        }
        $adminTarget = null;
        if ($path === '/admin') {
            [$adminPath, $adminTarget, $refused] = $this->adminRequest($request);
            if ($refused !== null) {
                $event->setResponse($this->adminRefusal($refused));

                return;
            }
            $path = $adminPath;
        }
        $reason = self::groupDecision($request->getMethod(), $path, $adminTarget);
        // relecture neuve de G-1 : sur un écran CRUD, seules les actions ordinaires d'EasyAdmin ; une action propre à un écran (rembourser le
        // dernier paiement, annuler un abonnement, remettre un NIP à zéro…) est fermée par défaut au directeur
        $crudAction = $request->query->get('crudAction');
        if ($reason === null && $path === '/admin' && $adminTarget !== null && is_string($crudAction) && $crudAction !== '' && !in_array($crudAction, self::GROUP_EA_ACTIONS, true)) {
            $reason = 'action';
        }
        // garde-fou du chef : la fiche du site ne change ni sa devise ni son terminal de paiement (champs du corps)
        if ($reason === null && preg_match('#^/api/entreprise(/|$)#', $path) === 1 && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $body = json_decode((string) $request->getContent(), true);
            foreach (is_array($body) ? array_keys($body) : [] as $key) {
                if (preg_match(self::GROUP_SHEET_CLOSED, (string) $key) === 1) {
                    $reason = preg_match('/email$/i', (string) $key) === 1 ? 'rights' : 'payment';
                }
            }
        }
        // relecture neuve, passe 2, et classement du chef : les écrans d'argent, de caisse, de journaux et de clés sont en LECTURE SEULE
        $crud = $request->query->get('crudControllerFqcn');
        if ($reason === null && $path === '/admin' && is_string($crud) && $crud !== '') {
            $reason = self::groupScreenDecision($crud, is_string($crudAction) && $crudAction !== '' ? $crudAction : 'index');
        }
        if ($reason !== null) {
            $event->setResponse(self::isAdmin($path) ? $this->adminRefusal($reason) : $this->refusal($reason));

            return;
        }
        $request->attributes->set(self::GROUP_ATTRIBUTE, true);
    }

    /**
     * Lot AG-ASSIST : la requête de l'opérateur est-elle couverte par une assistance vivante de SON agence (qui gère encore CE site), pour la
     * section de l'écran ? null : servie ; sinon la raison du refus. Lecture : toujours, hors comptes et rôles, exports de fait et
     * CLOSED_PATHS (clés, export, Stripe, modules). Écriture : seulement en mode « modifier » ET si la route est dans la liste blanche de
     * la section (`AssistanceSections::WRITES`) : fermé par défaut. La fenêtre d'une heure ne démarre que sur une requête ACCEPTÉE ; chaque
     * requête couverte (acceptée ou refusée) est écrite au journal du site AVANT d'être servie ; si cette trace ne s'écrit pas, la requête
     * est refusée (503).
     */
    private function assistanceRefused(Request $request, string $target, string $path, ExternalOperator $operator): ?string
    {
        if ($this->assistance === null || $this->moduleOff($target) !== null) {
            return 'family';
        }
        $sections = \App\Services\Agency\Assistance\AssistanceSections::of($target, ScreenRegistry::familyOf($target));
        $method = $request->getMethod();
        // relecture d'AG-3c : dans EasyAdmin, une action GET peut écrire (rembourser, confirmer, exporter) ; seule une liste ou une fiche lit
        $write = self::isAdmin($path) ? !(bool) $request->attributes->get(self::EA_READ, false) : in_array($method, self::WRITE, true);
        $tenant = (string) ($this->tenants?->getTenantCode() ?? '');
        $grant = $sections === [] ? null : $this->assistance->covering($tenant, $operator->getAgencyId(), $sections, $write);
        if ($grant === null) {
            return 'family';
        }
        $reason = null;
        foreach ([...array_map(static fn (array $c): string => $c[0], self::CLOSED_PATHS), ...self::ASSISTANCE_CLOSED] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $reason = 'assistance_closed';
                break;
            }
        }
        if ($reason === null && $write) {
            if ($grant['mode'] !== \App\Services\Agency\Assistance\AssistanceSections::WRITE) {
                $reason = 'assistance_read_only';
            } elseif (!\App\Services\Agency\Assistance\AssistanceSections::writeAllowed((string) $grant['section'], $method, $path)) {
                $reason = 'assistance_closed';
            }
        }
        if ($reason !== null) {
            $this->traceAssistance($request, $operator, $grant, $target, 'assistance_refused', $reason); // le refus aussi se lit au journal

            return $reason;
        }
        $operator->duringAssistance((int) $grant['id']);
        $traceId = $this->traceAssistance($request, $operator, $grant, $target, $write ? 'assistance_write' : 'assistance_read', null);
        if ($traceId === null) {
            return 'assistance_trace'; // pas de lecture sans trace (et la fenêtre ne s'ouvre pas)
        }
        $grant = $this->assistance->start($grant); // la première requête ACCEPTÉE et tracée ouvre la fenêtre d'une heure
        $request->attributes->set(self::ASSISTANCE_ATTRIBUTE, ['id' => (int) $grant['id'], 'section' => (string) $grant['section'], 'screen' => $target, 'trace' => $traceId]);

        return null;
    }

    /** Lot AG-3c : les actions d'un écran CRUD d'EasyAdmin qui ne font que LIRE (une assistance en consultation n'ouvre qu'elles) */
    private const EA_READS = ['index', 'detail'];
    /** Lot G-1 : champs de la fiche du site (API et EasyAdmin) que le directeur ne voit ni n'écrit : devise, terminal et paiements Stripe */
    // (décision du chef : l'adresse qui reçoit les commandes, et celle des demandes, sont fermées : rediriger les commandes revient à les détourner)
    /** Lot ABO-3b : champs d'une prestation que l'agence n'écrit pas (API et EasyAdmin, `AdminFieldGuard`) */
    public const BENEFIT_STAFF_FIELDS = ['atCustomer', 'defaultStaffUserId'];

    public const GROUP_SHEET_CLOSED = '/^(pos(Terminal|TapToPay|Bluetooth|Stripe)|currency$|stripe|email$|formSubmissionsEmail$)/i';
    /** Lot G-1b : les seules actions d'un écran CRUD servies au directeur d'un groupe */
    public const GROUP_EA_ACTIONS = ['index', 'detail', 'new', 'edit', 'delete', 'autocomplete', 'renderFilters'];
    /**
     * Lot G-1 (garde-fou du chef, classement des écrans un à un) : écrans d'EasyAdmin servis au directeur EN LECTURE SEULE (ni créer, ni
     * modifier, ni supprimer), avec la raison : l'argent (paiements, caisse, commandes, étiquettes achetées, formules d'abonnement, journal
     * des unités IA), les journaux inaltérables, les clés de services tiers.
     */
    public const GROUP_EA_READ_ONLY = [
        'PaymentsCrudController' => 'payment', 'PaymentMethodCrudController' => 'payment', 'PaymentTypeCrudController' => 'payment', 'StatusPaymentCrudController' => 'payment',
        'OrderCrudController' => 'payment', 'OrderAllCrudController' => 'payment', 'OrderItemsCrudController' => 'payment', 'OrderItemsListController' => 'payment', 'OrderTaxCrudController' => 'payment',
        'SubscriptionPlanCrudController' => 'payment', 'ShippingLabelCrudController' => 'payment', 'AiUsageCrudController' => 'payment',
        // lot ABO-CONF (relecture neuve) : les prestations d'une formule donnent de la valeur sans paiement, comme la formule elle-même
        'BenefitCrudController' => 'payment', 'PlanBenefitCrudController' => 'payment',
        'CaisseCrudController' => 'cash', 'CashDetailsCrudController' => 'cash', 'CashDetailsListController' => 'cash', 'CashRegisterCrudController' => 'cash',
        'CashRegisterReportCrudController' => 'cash', 'TransactionCaisseCrudController' => 'cash', 'TransactionCaisseListController' => 'cash',
        'TransactionTypeCrudController' => 'cash', 'TypeCashCrudController' => 'cash', 'PosAuditLogCrudController' => 'cash',
        'EasyPostConfigurationCrudController' => 'keys', 'GooglePlacesConfigCrudController' => 'keys',
        // lot CMD-WEB-1 : le directeur lit les rendez-vous, il ne les confirme, n'annule ni ne modifie (le formulaire change le statut et écrit au client)
        'ReservationCrudController' => 'rights',
    ];

    /** Lot G-1 : la décision pour une action d'un écran CRUD d'EasyAdmin servie au directeur ; null = servie */
    public static function groupScreenDecision(string $crudFqcn, string $action): ?string
    {
        $target = ScreenRegistry::adminCrud($crudFqcn);
        if ($target === null) {
            return 'unclassified';
        }
        $reason = self::groupDecision('GET', '/admin', $target);
        if ($reason !== null) {
            return $reason;
        }
        if (!in_array($action, self::GROUP_EA_ACTIONS, true)) {
            return 'action';
        }
        $short = substr($crudFqcn, (int) strrpos($crudFqcn, '\\') + 1);

        return in_array($action, ['new', 'edit', 'delete'], true) ? (self::GROUP_EA_READ_ONLY[$short] ?? null) : null;
    }
    /** Attribut posé sur une requête d'EasyAdmin : vrai si elle ne fait que lire */
    private const EA_READ = '_entry_ea_read';

    /**
     * Lot AG-3c (après la double relecture) : une requête `/admin` de l'opérateur, ramenée à ce qu'EasyAdmin va réellement exécuter.
     * - `routeName` : la page nommée, jugée sur SON chemin (règles par chemin, corps, ouverture de la boutique, assistance) ; seulement une
     *   route d'EasyAdmin (chemin `/admin/…`), jamais avec `crudControllerFqcn` (EasyAdmin exécuterait l'une sous l'étiquette de l'autre).
     * - `crudControllerFqcn` : l'écran CRUD ; une action par lot est refusée (la suppression par lot lit l'entité dans le corps).
     * Rend [chemin jugé, cible du registre ou null, raison du refus ou null], et pose l'attribut de lecture seule.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function adminRequest(Request $request): array
    {
        $routeName = $request->query->get('routeName');
        $crud = $request->query->get('crudControllerFqcn');
        if (is_string($routeName) && $routeName !== '') {
            if ($crud !== null && $crud !== '') {
                return ['/admin', null, 'ambiguous'];
            }
            try {
                $params = $request->query->all('routeParams');
                $generated = $this->router?->getRouteCollection()->get($routeName) !== null ? $this->router->generate($routeName, $params) : null;
            } catch (\Throwable) {
                $generated = null;
            }
            $routePath = is_string($generated) ? (string) parse_url($generated, \PHP_URL_PATH) : '';
            if (!str_starts_with($routePath, '/admin/')) {
                return ['/admin', null, 'surface'];
            }
            $request->attributes->set(self::EA_READ, in_array($request->getMethod(), ['GET', 'HEAD'], true));

            return [$routePath, null, null];
        }
        $action = $request->query->get('crudAction');
        $action = is_string($action) && $action !== '' ? $action : 'index';
        if (str_starts_with(strtolower($action), 'batch') || $request->request->has('batchActionName') || $request->request->has('batchActionEntityIds')) {
            return ['/admin', null, 'batch'];
        }
        $request->attributes->set(self::EA_READ, ($crud === null || $crud === '' || in_array($action, self::EA_READS, true)) && in_array($request->getMethod(), ['GET', 'HEAD'], true));
        // lot ABO-CONF (décision du chef) : une agence entrée configure les formules de son client, n'en supprime jamais une, ici comme par l'API
        if ($action === 'delete' && is_string($crud) && str_ends_with($crud, '\\SubscriptionPlanCrudController')) {
            return ['/admin', null, 'delete'];
        }

        return ['/admin', ScreenRegistry::adminTarget(is_string($crud) ? $crud : null, null, $request->getMethod()) ?? '?', null];
    }

    /** Lot AG-3c : le refus d'une page d'EasyAdmin, en page HTML */
    private function adminRefusal(?string $reason): Response
    {
        $html = $this->twig?->render('admin/entree/refus.html.twig', ['reason' => (string) $reason])
            ?? '<!DOCTYPE html><html lang="fr"><meta charset="utf-8"><title>Accès refusé</title><p>Cette page n\'est pas ouverte à une agence dans le site de son client.</p></html>';

        return new Response($html, $reason === 'assistance_trace' ? 503 : 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function refusal(?string $reason): JsonResponse
    {
        if ($reason === 'assistance_trace') {
            return new JsonResponse(['error' => 'SERVICE_UNAVAILABLE', 'code' => 'SERVICE_UNAVAILABLE', 'reason' => $reason,
                'message' => 'Le journal de l\'assistance est indisponible : la page n\'est pas servie.'], 503);
        }

        return new JsonResponse(['error' => self::CODE, 'code' => self::CODE, 'reason' => $reason,
            'message' => 'Cette page n\'est pas ouverte à une agence dans le site de son client.'], 403);
    }

    /** Ouvrir ou fermer la boutique : seulement l'agence qui a CRÉÉ le site (un site rattaché en service ne se ferme pas par l'agence) */
    private function storefrontRefused(Request $request, string $path, ExternalOperator $operator): ?string
    {
        $gesture = preg_match('#^/api/site/storefront/(open|close)$#', $path) === 1
            || (preg_match('#^/admin/ouverture-du-site/(ouvrir|refermer)$#', $path) === 1 && $request->isMethod('POST')); // lot AG-3c : le même geste dans EasyAdmin

        return $gesture && $operator->getOrigin() !== 'created' ? 'storefront' : null;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && is_array($request->attributes->get(self::ASSISTANCE_ATTRIBUTE))) {
            $this->traceStatus((int) ($request->attributes->get(self::ASSISTANCE_ATTRIBUTE)['trace'] ?? 0), $event->getResponse()->getStatusCode());

            return; // une section ouverte par le commerçant : servie entière (ni champs sensibles de la construction à retirer, ni filtre)
        }
        if ($event->isMainRequest() && $request->attributes->get(self::GROUP_ATTRIBUTE) === true) {
            $this->traceWrite($request, $event->getResponse()->getStatusCode()); // le directeur : tout servi, chaque écriture tracée « Groupe X, prénom »

            return;
        }
        $target = $request->attributes->get(self::ATTRIBUTE);
        $response = $event->getResponse();
        if (!is_string($target) || !$event->isMainRequest()) {
            return;
        }
        $this->traceWrite($request, $response->getStatusCode());
        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
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
            // une réponse JSON qu'on ne sait pas relire ne part pas sans filtre
            $event->setResponse(new JsonResponse(['error' => 'ERROR', 'code' => 'ERROR', 'message' => 'Réponse indisponible.'], 500));

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

    /**
     * Chaque écriture réussie de l'opérateur porte son nom au journal du site (« Agence X, prénom »), même quand la route n'écrit pas
     * elle-même au journal (écritures d'API Platform, médias, apparence, codes-barres…). Ne fait jamais échouer la réponse.
     */
    private function traceWrite(Request $request, int $status): void
    {
        $operator = $this->tokens->getToken()?->getUser();
        // (lot G-1b : les actions d'EasyAdmin qui écrivent en GET sont fermées au directeur : seules les actions ordinaires lui sont servies)
        if (!in_array($request->getMethod(), self::WRITE, true) || $status >= 400 || !($operator instanceof ExternalOperator || $operator instanceof GroupOperator)) {
            return;
        }
        try {
            $this->emProvider->getEntityManager()->getConnection()->insert('content_audit_log', [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_identifier' => mb_substr($operator->getUserIdentifier(), 0, 180),
                'tenant' => (string) ($this->tenants?->getTenantCode() ?? ''), 'resource' => \App\Services\Entry\EntryService::JOURNAL_RESOURCE, 'resource_id' => (string) $operator->getEntryId(),
                'action' => 'write', 'fields' => 'request', 'after_state' => json_encode(['method' => $request->getMethod(), 'path' => mb_substr(RequestPath::of($request), 0, 200), 'status' => $status]
                    + ($operator instanceof GroupOperator ? ['mode' => \App\Services\Entry\EntryService::MODE_GROUP] : [])
                    // l'écran, l'action et la fiche d'une requête d'EasyAdmin (sinon la ligne ne dirait que « POST /admin »)
                    + ($operator instanceof GroupOperator && RequestPath::of($request) === '/admin' ? array_filter(['screen' => mb_substr((string) $request->query->get('crudControllerFqcn', ''), 0, 120),
                        'action' => mb_substr((string) $request->query->get('crudAction', ''), 0, 40), 'entityId' => mb_substr((string) $request->query->get('entityId', ''), 0, 20),
                        'route' => mb_substr((string) $request->query->get('routeName', ''), 0, 80)], static fn (string $v): bool => $v !== '') : []), \JSON_UNESCAPED_SLASHES), // lot G-1b : lue par « Accès extérieurs »
            ]);
        } catch (\Throwable) {
            // la trace manque : la réponse part quand même (l'écriture est faite)
        }
    }

    /**
     * Lot AG-ASSIST : CHAQUE requête couverte par une assistance (écran consulté, fiche lue, écriture, refus) est écrite au journal du site
     * AVANT d'être servie, lue par « Accès extérieurs » : l'auteur « Agence X, prénom, pendant l'assistance n° N », la méthode, le chemin, la
     * raison d'un refus ; le statut de la réponse est ajouté ensuite. Rend le numéro de la ligne, ou null si elle n'a pas pu s'écrire.
     *
     * @param array<string, mixed> $grant
     */
    private function traceAssistance(Request $request, ExternalOperator $operator, array $grant, string $screen, string $action, ?string $reason): ?int
    {
        try {
            $connection = $this->emProvider->getEntityManager()->getConnection();
            $connection->insert('content_audit_log', [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_identifier' => mb_substr($operator->getUserIdentifier(), 0, 180),
                'tenant' => (string) ($this->tenants?->getTenantCode() ?? ''), 'resource' => \App\Services\Entry\EntryService::JOURNAL_RESOURCE, 'resource_id' => (string) $operator->getEntryId(),
                'action' => $action, 'fields' => 'request',
                'after_state' => json_encode(['assistance' => (int) $grant['id'], 'section' => (string) $grant['section'], 'screen' => $screen, 'method' => $request->getMethod(),
                    'path' => mb_substr(RequestPath::of($request), 0, 200), 'query' => mb_substr((string) $request->getQueryString(), 0, 200), 'reason' => $reason, 'status' => null], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int) $connection->lastInsertId();

            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Le statut de la réponse, ajouté à la ligne du journal écrite avant de servir (sans lui, la ligne dit déjà la requête) */
    private function traceStatus(int $traceId, int $status): void
    {
        if ($traceId <= 0) {
            return;
        }
        try {
            $this->emProvider->getEntityManager()->getConnection()->executeStatement(
                "UPDATE content_audit_log SET after_state = jsonb_set(after_state::jsonb, '{status}', to_jsonb(?::int))::text WHERE id = ? AND resource = ?",
                [$status, $traceId, \App\Services\Entry\EntryService::JOURNAL_RESOURCE]
            );
        } catch (\Throwable) {
            // le statut manque : la ligne dit déjà la requête
        }
    }

    /** Ce que le corps ou l'objet de la requête ferme : le coût d'un article, un champ réservé de la fiche ou du registre, un import de clients ou de commandes */
    private function bodyRefused(Request $request, string $target, string $path): ?string
    {
        if ($target === 'site.modules' && $path === '/api/site/features' && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            foreach (is_array($body) && is_array($body['values'] ?? null) ? array_keys($body['values']) : [] as $key) {
                $reason = self::featureKeyRefused((string) $key);
                if ($reason !== null) {
                    return $reason;
                }
            }
        }
        if ($target === 'site.reglages' && preg_match('#^/api/entreprise(/|$)#', $path) === 1 && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            if (is_array($body) && array_filter(array_map('strval', array_keys($body)), [SiteSheetClosedFields::class, 'closed']) !== []) {
                return 'field';
            }
        }
        // lot ABO-3b (décision du chef, 20/11/2026) : montrer l'adresse des abonnés à un intervenant, ou lui confier une prestation, reste un
        // geste du commerçant ; l'agence écrit le reste d'une prestation
        if ($target === 'abonnements.formules' && preg_match('#^/api/site/abonnements/prestations(/|$)#', $path) === 1 && in_array($request->getMethod(), self::WRITE, true)) {
            $body = json_decode((string) $request->getContent(), true);
            if (is_array($body) && array_intersect(self::BENEFIT_STAFF_FIELDS, array_keys($body)) !== []) {
                return 'field';
            }
        }
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

    /**
     * Une clé du registre des fonctionnalités que l'agence n'écrit pas (LISTE BLANCHE, `FeatureCatalog::AGENCY_WRITABLE`) : `modules` pour
     * une clé `platform`, `field` pour toute autre, inconnue comprise ; null : clé de construction.
     */
    public static function featureKeyRefused(string $key): ?string
    {
        if (in_array($key, \App\Services\FeatureAccess\FeatureCatalog::AGENCY_WRITABLE, true)) {
            return null;
        }

        return \App\Services\FeatureAccess\FeatureCatalog::exists($key) && \App\Services\FeatureAccess\FeatureCatalog::get($key)['scope'] === \App\Services\FeatureAccess\FeatureCatalog::SCOPE_PLATFORM ? 'modules' : 'field';
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
