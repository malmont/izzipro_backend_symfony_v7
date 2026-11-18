# Registre des écrans et contextes du compte (lot AG-3a, 16/11/2026)

Premier lot du plan « entrée dans un autre site et multi-boutiques » (`docs/plan-entree-et-groupe.md`). Il pose la pièce commune à
tous les modes : **un registre des écrans**, et **une route qui dit à chaque compte son mode, ses sites et ses écrans**.

**Ce lot ne change aucun droit.** Le registre dit ce qu'un menu peut MONTRER. Refuser une route à un mode est le lot AG-3b, qui lira
ce même registre : une route que le registre ne range pas y sera fermée, jamais ouverte.

Code : `src/Services/Screens/` (`ScreenRegistry`, `ContextService`, `SiteSnapshotReader`), `src/Controller/Account/
MeContextsController.php`. Essais : `tests/Functional/Screens/`. Classement figé : `tests/Fixtures/ecrans/classement.json`.

## 1. Le registre (`ScreenRegistry`)

Un écran a un **code**, une **famille**, un **module** (clé de `FeatureCatalog::GOVERNED`, ou aucun), des **rôles** (un seul suffit ;
`ROLE_ADMIN` à défaut) et une **surface** : `web` (espace d'administration du web, application de gestion) ou `easyadmin`.

| Famille | Ce qu'elle réunit |
|---|---|
| `construction` | catalogue, codes-barres, médias, pages, apparence, réglages du site, domaine, modules, ouverture, import du catalogue, générateur IA de pages et de fiches |
| `vente` | commandes et paiements (EasyAdmin, et les routes `/api/orders`, `/api/payments`, achat d'une étiquette, annulation : `ventes.commandes`), connexion Stripe et Square, caisse, expéditions, abonnements, mouvements de stock, **assistant du site**, **journal des modifications**, import des commandes |
| `clients` | comptes, demandes reçues, candidatures, avis, rendez-vous (`clients.reservations`), infolettre, import des clients |
| `gestion` | achats, périodes, trésorerie, statistiques, console IA, bilans |
| `pertes` | prévention des pertes |
| `assistance` | demandes des clients (module Assistance) |
| `contenus` | Mémoires Vivantes et Boussole (livres, réponses : données personnelles) |
| `plateforme` | écrans du super administrateur |
| `agence` | l'espace d'une agence, lu de son site d'attache |

Les codes de la gestion et des pertes sont ceux que les applications envoient déjà (`ConsoleScreens::SCREENS`, guides d'écran,
`AdminSpaceLinks::LINKS`, « Ma journée », séquences) : ces listes continuent de marcher, et un essai vérifie que tous leurs codes sont
au registre. Les écrans d'EasyAdmin sont préfixés `admin.` : un par section du menu, coupé là où la famille change
(`admin.catalogue`, `admin.commandes`, `admin.commandes-reglages`…).

### Le rangement des routes

`ScreenRegistry::ROUTES` est une liste ordonnée d'expressions : la première qui reconnaît le chemin décide. La cible est un écran ou
une **liste hors écran** nommée ; quand lecture et écriture n'ont pas le même propriétaire, une cible par méthode
(`GET /api/bannieres` est du site public, `POST /api/bannieres` est de l'écran `pages.contenus`).

| Liste hors écran | Contenu |
|---|---|
| `@public` | LECTURES du site public (catalogue, pages, référentiels) et envois d'un visiteur (devis du panier, paiement, formulaires, prise de rendez-vous). Aucune écriture d'administration : sur ces mêmes chemins, une écriture est celle de l'écran de contenu |
| `@compte` | connexion et compte de la personne connectée (profil, adresses, ses commandes passées, ses abonnements, ses avis), dont `GET /api/me/contexts`. Les routes où un administrateur agit sur tout le site n'y sont pas |
| `@apps` | démarrage des applications : recherche du site, activation, profil, thème |
| `@caisse` | application de caisse |
| `@memoires`, `@boussole` | Mémoires Vivantes / Living Memory, espace des entreprises de la Boussole |
| `@boitier` | boîtier de la prévention des pertes |
| `@technique` | état du serveur, webhooks, fichiers, synchronisation du front, documentation d'API Platform |
| `@ancien` | anciennes pages de la boutique servies par le backend |

Les écrans CRUD d'EasyAdmin passent tous par l'adresse `/admin` : `ScreenRegistry::ADMIN_CRUDS` range chaque contrôleur CRUD par
son nom court, quel que soit ce nom (cinq s'appellent `…ListController`).

**Une requête d'EasyAdmin ne se classe jamais par son chemin.** `/admin?crudControllerFqcn=…` ouvre un écran CRUD, et
`/admin?routeName=…` exécute n'importe quelle route nommée sous le chemin `/admin` (une page de remboursement, par exemple).
`ScreenRegistry::adminTarget($crud, $cheminDeLaRoute, $méthode)` rend l'écran de la requête ; un écran ou une route inconnus n'ont
pas d'écran, donc seront fermés. Les gardes d'AG-3b et d'AG-3c passent par elle.

### Tenir le registre

`tests/Functional/Screens/ScreenRegistryTest.php` relit **toutes les routes** de l'application (API Platform comprise) et **tous les
écrans CRUD** d'EasyAdmin (toute classe qui hérite du contrôleur CRUD, trouvée par son héritage et non par son nom) :

- une route rangée nulle part fait échouer l'essai ; une règle qui ne range plus rien aussi ;
- le classement fige des GABARITS de route : la route générique `PATCH /api/{resource}/{id}` s'exécute, elle, sous le chemin de
  chaque ressource ; l'essai vérifie donc aussi que chacune de ses ressources, par son vrai chemin, est une écriture de construction ;
- le classement entier est figé dans `tests/Fixtures/ecrans/classement.json` (cible → routes). **Toute route nouvelle change ce
  fichier : son diff se relit**, car une route de vente rangée en construction s'ouvrirait à une agence. Régénérer :

```
docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled -e REGENERER_CLASSEMENT=/tmp/classement.json symfony_app_v2 \
  php vendor/bin/phpunit tests/Functional/Screens/ScreenRegistryTest.php
docker exec symfony_app_v2 cat /tmp/classement.json > tests/Fixtures/ecrans/classement.json
```

## 2. Les modes

Le mode se **calcule** depuis les liens réels, jamais d'un champ tenu à la main.

| Mode | Ce qui le fonde | Sites rendus | Écrans |
|---|---|---|---|
| `single` (boutique seule) | à défaut | le site de la connexion, et lui seul | familles d'un site, selon les modules actifs et les rôles du compte |
| `agency` | le compte porte un rôle d'agence ET le site de la connexion est l'attache d'une agence non fermée | le site d'attache (`home: true`), puis les sites que CETTE agence gère | attache : comme `single` ; client : famille `construction` seulement |
| `super_admin` | `ROLE_SUPER_ADMIN` | tous les sites | familles d'un site ; les écrans de la plateforme sont à part, dans `all` |

Le mode `group` viendra avec le lot G-1.

**Un compte ne voit que les sites qu'il peut ouvrir.** L'administrateur d'un site ne voit que son site, même quand ce site est
l'attache d'une agence ou qu'une agence le gère. Un site détaché sort de la liste ; une agence fermée n'ouvre plus le mode agence ;
une agence **suspendue** garde la liste de ses clients, sans aucun écran chez eux (son site d'attache reste le sien).

**Un compte client n'apprend rien** que le site public ne dise déjà : son site, son état, aucun écran, et ni les modules actifs ni
les liens d'agence (`modules: []`, `badges: []`). Sur un site en construction, la garde de la boutique lui refuse cette route comme
toute autre (`docs/boutique-en-construction.md`). Le numéro d'une demande de création à compléter (`requestId`) n'est rendu qu'au
super administrateur.

## 3. `GET /api/me/contexts`

Toute personne connectée (`IS_AUTHENTICATED_FULLY`), lecture seule, `Cache-Control: private, no-store`. Un compte borné à un espace
(agence seule, assistance seule) la lit aussi : c'est la seule route que ses gardes lui ouvrent en plus, par son chemin exact.

```
{ mode: "single" | "agency" | "super_admin",
  site: "<code du site de la connexion>",
  agency: { id, code, name, status } | null,
  sites: [{ code, name, host, current, home?,
            badges: [{ type: "independent" | "agency_home" | "agency_managed" | "creation_incomplete", agencyId?, agencyCode?, agencyName?, requestId? }],
            state: "open" | "construction" | null, scope: "site" | "shop" | null,
            modules: ["module.boutique", …],        // clés des modules actifs sur ce site ([] pour un compte sans rôle d'équipe)
            screens: ["catalogue", "pages", …] }],  // codes des écrans que le menu peut montrer
  all: { key: "clients" | "sites", screens: [...] } | null }
```

- `state` et `modules` sont lus dans la base de chaque site (une connexion par site, aucune écriture) ; un site illisible rend
  `state: null` et aucun module.
- `all` est l'entrée « tous » du mode : « Tous mes clients » (`agence.clients`) pour une agence, les écrans de la plateforme pour le
  super administrateur ; `null` pour une boutique seule.
- Les fronts construisent leur sélecteur et leur menu de cette réponse, sans coder les règles d'un mode. Un code inconnu d'un front
  s'ignore ; les écrans `admin.*` sont ceux d'EasyAdmin.

Exemple réel, compte d'agence seul (sites de test) :

```json
{ "mode": "agency", "site": "mvtest",
  "agency": { "id": 1, "code": "agence-ctx-a47304", "name": "Agence Contextes", "status": "trial" },
  "sites": [
    { "code": "mvtest", "name": "…", "host": "mvtest.test", "current": true, "home": true, "state": "open", "scope": null,
      "badges": [{ "type": "agency_home", "agencyId": 1, "agencyCode": "agence-ctx-a47304", "agencyName": "Agence Contextes" }],
      "modules": ["module.landing", "module.boutique", "…"], "screens": [] },
    { "code": "mvtest2", "name": "…", "host": "mvtest2.test", "current": false, "home": false, "state": "open", "scope": null,
      "badges": [{ "type": "agency_managed", "agencyId": 1, "agencyCode": "agence-ctx-a47304", "agencyName": "Agence Contextes" }],
      "modules": ["module.landing", "module.boutique", "…"],
      "screens": ["catalogue", "catalogue.ia", "medias", "codes-barres", "pages", "pages.contenus", "pages.ia", "apparence",
        "site.reglages", "site.domaine", "site.modules", "site.ouverture", "site.aide", "imports", "admin.accueil",
        "admin.entreprise", "admin.vitrine", "admin.boutique-pages", "admin.pages-avancees", "admin.ia", "admin.medias",
        "admin.catalogue", "admin.livraison", "admin.taxes", "admin.location", "admin.commandes-reglages"] }],
  "all": { "key": "clients", "screens": ["agence.clients"] } }
```

Boutique seule : `mode: "single"`, un seul site, `agency: null`, `all: null`, et les écrans de toutes les familles du site (112 pour
un administrateur dont tous les modules sont actifs ; `["assistance", "assistance.demande"]` pour un agent d'assistance ; `[]` pour un
compte client). Super administrateur : `mode: "super_admin"`, tous les sites, `all: { key: "sites", screens: ["plateforme.pilotage",
"plateforme.agences", "plateforme.sites", "admin.maintenance"] }`.

## 4. Mode agence : ce qui est en construction, ce qui n'y est pas

Conforme au classement validé par le propriétaire (`docs/plan-entree-et-groupe.md`) : l'agence construit et entretient, elle ne lit
jamais un chiffre, un client ni une commande. Elle voit les quantités en stock, jamais le coût. Le générateur IA (pages, boutique,
fiche produit) est en construction, ouvert et tracé ; la console IA de la gestion n'y est pas.

Sortis de la construction parce que leur réponse ENTIÈRE porte des ventes ou des clients :

| Écran | Routes | Pourquoi |
|---|---|---|
| `site.assistant`, `admin.assistant` | `/api/site/assistant`, `/admin/mon-site/assistant` | l'assistant du site lit les commandes et les clients, et propose remboursement, annulation, capture |
| `site.journal` | `/api/landingpage-audit` | le journal garde l'avant / après de tout le site : coûts, commandes, adresses, comptes |
| `imports.clients` | `/api/site/imports/{id}/customers`, `/{id}/invitations` | nominatif |
| `imports.commandes` | `/api/site/imports/archive/orders` | commandes d'avant, lignes et montants |
| `ventes.commandes` | `GET /api/orders`, `GET /api/payments`, `POST /api/shipping/buy`, `POST /api/order/cancel/{id}` | un client y lit ou annule les siens, un administrateur ceux de tout le site |
| `clients.reservations` | `GET /api/reservations`, `…/{id}/confirm` | tous les rendez-vous du site |
| `admin.paiement` | Stripe (`/admin/stripe`, `/stripe/connect`…), Square | le commerçant connecte lui-même son compte de paiement |
| `admin.stock` | mouvements de stock | l'historique des mouvements dit les ventes |
| `admin.utilisateurs` | comptes, adresses | l'agence ne crée pas de comptes d'équipe chez son client |

## 5. Champs sensibles des écrans de construction (`ScreenRegistry::SENSITIVE`)

Un écran de construction peut rendre, au milieu du reste, ce qu'une agence ne doit pas lire. Ce lot les NOMME (inventaire du
16/11/2026, `var/reprise/inventaire-champs-sensibles.md`) ; la garde d'AG-3b les retirera des réponses du mode agence (`fields`) et
fermera ce qui ne se filtre pas (`closed`).

| Écran | Champs à retirer | À fermer |
|---|---|---|
| `catalogue` | `purchasePrice`, `coefficientMultiplier`, `commande`, `commandeCostCents`, `commandeQuantity`, `commandeAttachedAt`, `commandeCostEstimated`, `bookings`, `reviewsProducts` (les écritures d'API Platform d'un article rendent toute l'entité) ; dans le refus de suppression `PRODUCT_IN_USE` : `uses.sales`, `uses.purchaseLines`, `uses.bookings`, `uses.openCheckouts` | écrire `purchasePrice` ou `coefficientMultiplier` par `POST`, `PUT`, `PATCH /api/products` |
| `admin.catalogue` | colonnes « Prix d'achat », « Coefficient », « Dernière commande fournisseur » | |
| `admin.accueil` | toutes les variables de ventes du tableau de bord (`totalSales`, `salesThisMonth`, `totalOrders`, `avgOrderValue`, `totalCustomers`, `totalTaxes`, `ordersTodo`, séries des courbes, `recentOrders`…) | **la page d'accueil entière** (une page de construction la remplacera) ; la console flottante de l'assistant ; toute requête `/admin?routeName=…` se classe par sa route |
| `admin.entreprise` | `figures` et le texte `description` des étapes de la gestion (« Mon site », Configuration) ; le mot de passe du serveur d'envoi, visible dans la fiche de la configuration du courriel | l'étape `stripe.first_payment` (sa description compte les paiements par carte) ; créer une clé pour les applications `admin` et `pos` |
| `admin.vitrine` | `candidatures` : le compte des candidatures reçues, sur l'écran des offres d'emploi | |
| `admin.commandes-reglages` | `orders` (références des commandes d'un statut ou d'un type), `payments` (identifiants des paiements d'un type) dans les fiches détail | |
| `admin.taxes` | `orderTaxes` : le compte des lignes de taxe des commandes | |
| `admin.ia`, `pages.ia` | `promptExcerpt` : début des questions posées à la console de gestion | |
| `site.reglages` | `charges` de `/api/site/shipping` (achats d'étiquettes par commande) ; `figures` et `description` des étapes de `/api/site/readiness` | `POST /api/app-keys` pour les applications `admin` et `pos` |
| `imports` | `summary.orders`, `summary.customers`, `summary.guests`, `summary.archived`, `report.orders`, `result.cancel.kept[].reason` | les imports `kind=customers` et `kind=orders` |

Le coût d'achat et le coefficient des fiches produit PUBLIQUES, trouvés par ce même inventaire, ont été retirés pour tous par le lot
COST-1 (`docs/boutique.md`, « Le coût ne sort pas des routes ouvertes ») : ils n'ont plus à être nommés ici.

## 6. Suite

- **AG-3b** : codé, voir § 7.
- **AG-3c** : menu d'EasyAdmin piloté par le registre (mode et modules), garde sur toutes les sections.
- **AG-3d** : « Tous mes clients ».

## 7. Lot AG-3b : l'entrée dans le site d'un autre (16/11/2026, codé sous Opus, à faire vérifier par Fable)

Contrat validé par le chef de projet le 16/11/2026, avec six précisions. API seulement : EasyAdmin est AG-3c ; le mode groupe est G-1.

### 7.1 Entrer

`POST /api/me/contexts/enter`, corps `{ "site": "<code du site>" }`, depuis le SITE D'ATTACHE (jeton ou cookie de ce site). Le lien
est vérifié dans la base maître :

- **agence** (compte portant `ROLE_AGENCY` ou `ROLE_AGENCY_MEMBER`) : l'agence dont ce site est l'attache, à l'état `trial` ou
  `active` (ni suspendue, ni fermée), et le site visé géré par elle, non détaché ;
- **plateforme** (compte de la plateforme, `ROLE_SUPER_ADMIN`) : tout site qui porte son compte de la plateforme (il existe sur chaque
  site, § 8.9 de `docs/agences-et-import.md`).

Réponse 200 (jamais en cache partagé) :

```json
{ "token": "<jeton d'entrée>", "expiresAt": "2026-11-16T19:42:10+00:00", "mode": "agency", "site": "boutique-du-client",
  "entry": 42, "operator": "Agence Atelier Web, Julie" }
```

Le front présente ensuite `Authorization: Bearer <token>` avec l'en-tête `X-Tenant-Host` du site visé. Le jeton vaut 15 minutes, pour CE
site seulement (claim `tenant_code`) : présenté sur le site d'attache ou sous l'hôte d'un autre site, 401. On le RENOUVELLE en
redemandant l'entrée depuis le site d'attache, ce qui exige une session encore valide là-bas ; un jeton d'entrée n'en obtient jamais un
autre, ni vers un troisième site (403). L'entrée exige une SESSION VIVANTE sur le site d'attache : un jeton de renouvellement valable
(la déconnexion le révoque) ; sinon 403 `ENTRY_FORBIDDEN` (`reason` : `session`). Limite : 30 entrées par 10 minutes par compte.
Sans la trace au journal du site visé, pas d'entrée (503 `ENTRY_UNAVAILABLE`, `reason` : `trace`).

Refus : 403 `ENTRY_FORBIDDEN` (`reason` : `role`, `agency`, `site` ; un site inconnu se refuse comme un site non géré), 422
`ENTRY_SAME_SITE`, 409 `ENTRY_UNAVAILABLE` (plateforme : pas de compte de la plateforme sur ce site), 403 `ENTRY_FROM_ENTRY`, 429
`RATE_LIMITED`.

### 7.2 Dans le site visé

- **Agence** : la requête est faite par un OPÉRATEUR EXTÉRIEUR (`App\Security\Entry\ExternalOperator`) : rien n'est créé dans la base du
  client, aucun mot de passe n'y existe. Il porte les rôles d'un administrateur (pour passer les règles d'accès des écrans de
  construction) et le rôle `ROLE_ENTRY_AGENCY`, jamais `ROLE_SUPER_ADMIN` : le générateur IA consomme les unités du client, et les
  drapeaux de modules de la fiche du site lui restent fermés. Son identifiant est « Agence X, prénom » (sans arobase) : c'est ce que le
  commerçant lit au journal de son site et dans « Accès extérieurs ».
- **Plateforme** : la requête est faite par le compte de la plateforme du site visé, avec tous ses écrans de site.

À chaque requête, la ligne de l'entrée est RELUE dans la base maître (fermeture, échéance, site, mode) ; le reste du lien (compte du
site d'attache supprimé ou d'une autre adresse, rôle retiré, agence suspendue ou fermée, site détaché) est gardé une minute au plus
(`EntryService::check`). Lien rompu → 401 `ENTRY_ENDED`, et l'entrée est fermée au journal avec sa raison ; site d'attache illisible →
401 sans fermer. La DÉCONNEXION du site d'attache (`POST /api/logout`) ferme aussitôt toutes les entrées du compte, reconnu par son
jeton d'accès ou par le jeton de renouvellement qu'elle révoque. Les lecteurs du jeton hors du pare-feu (`StorefrontViewer`,
`OptionalCustomerResolver`) relisent le lien de même ; un jeton d'entrée n'y est jamais un client.

Le compte de la plateforme en entrée n'écrit rien sur un compte (`@compte` : profil, adresse, mot de passe), car une telle écriture
rendrait un jeton ordinaire : 403 `MODE_FORBIDDEN` (`reason` : `account`).

### 7.3 La garde du mode agence (`EntryModeGuardSubscriber`), fermée par défaut

1. La route se classe par ce registre (`classify`, sur le chemin décodé). Ouverte seulement si son écran est de la famille
   **construction** et que son module est actif chez le client. Route non rangée, liste hors écran, autre famille, module éteint,
   chemin hors de `/api/` : 403 `MODE_FORBIDDEN` (`reason` : `unclassified`, `off_screen`, `family`, `module`, `surface`).
2. Les **lectures du site public** (`@public`, GET) sont servies comme à un visiteur : l'authentification est retirée (sinon les rôles
   d'administrateur de l'opérateur y ouvriraient ce qu'un administrateur lit). La garde de la CONSTRUCTION, elle, le traite comme un
   compte d'équipe (`StorefrontViewer`) : il voit la vitrine d'un site encore fermé. La garde du mode reste au-dessus.
3. Fermés même dans la construction (précisions du chef, relectures) : clés d'API et d'activation (`keys`), export (`export`),
   paiement (`payment`), changement des modules du site (`modules` : le forfait de l'agence n'y est pas encore relu) ; l'écriture du
   coût d'un article (`cost` : coût d'achat, coefficient, coût et quantité de la commande fournisseur) ; dans la fiche du site
   (`field`), l'adresse qui reçoit les commandes, les demandes et les réservations des clients, la devise, le fuseau et la fréquence
   des périodes (les TAXES restent à l'agence, décision du propriétaire : le commerçant confirme « Taxes vérifiées », AG-3d) ; dans
   `PUT /api/site/features`, une LISTE BLANCHE : seules les clés de `FeatureCatalog::AGENCY_WRITABLE` (langue, prix taxes comprises,
   modes de vente, achat sans compte, abonnements, calcul des taxes, livraison) s'écrivent ; toute autre clé, inconnue comprise, répond
   `field` (`modules` pour une clé de module) ; chaque clé du registre est classée (`AGENCY_WRITABLE` ou `AGENCY_CLOSED`) ; ouvrir ou fermer la boutique d'un site que l'agence n'a pas créé (`storefront`) ; l'import des
   clients et des commandes (`kind`) ; un import du catalogue confirmé en entrée n'écrit pas le coût d'achat du fichier. Comptes et
   rôles du site, Stripe et paiements, exports, pertes sont d'autres familles : fermés par la règle 1. `HEAD` suit la règle de `GET`.
4. La réponse d'un écran de construction perd ses **champs sensibles** (§ 5, par suffixe du chemin du champ) ; la liste des imports ne
   garde que les imports du catalogue ; une réponse JSON illisible ne part pas (500). Les écritures d'API Platform (article, catégorie)
   rendent toute l'entité et ses relations, jusqu'aux commandes et aux clients : pour l'opérateur, leur sortie reçoit le groupe de lecture
   borné de la ressource (`product:list`, `category:list`), ou aucun champ pour une autre ressource (`EntrySerializerContextBuilder`).
5. Chaque écriture réussie de l'opérateur laisse une ligne `external_access` / `write` (méthode, chemin, statut) au journal du site, à
   son nom, même là où la route n'écrit pas elle-même au journal.

La liste des routes ouvertes à l'agence est figée dans `tests/Fixtures/ecrans/entree-agence.json` (127 au 16/11/2026), comparée par
`EntryTest` à chaque suite : **une route nouvelle de la construction s'y ajoute en la relisant**. La confirmation « Taxes vérifiées »
de la liste d'ouverture n'existe pas encore (AG-3d) : elle sera réservée au commerçant.

### 7.4 Trace des deux côtés

- **Base maître**, table `site_entries` (`ops/sql/agency_master.sql`, `scripts/migrate_master_v2_entries.sh`) : qui (compte du site
  d'attache, adresse, prénom), de quel site, vers quel site, agence, mode, quand, échéance, fin et sa raison.
- **Site visé** : une ligne `external_access` au journal du site à chaque entrée ; la page « Accès de la plateforme » devient
  « **Accès extérieurs** » (`/admin/acces-plateforme`) et liste les connexions du compte de la plateforme et les entrées ; chaque
  écriture faite en entrée porte « Agence X, prénom » au journal du site (rien de plus : ni adresse, ni nom de famille).

### 7.5 Limites connues

- EasyAdmin n'est pas ouvert à l'entrée (AG-3c).
- `/api/team`, `/api/vehicles` et `/api/vehicle_products` figurent dans la liste figée mais sont hors du pare-feu de l'API : le jeton
  n'y est pas lu, l'opérateur y reçoit 401. `GET /api/me/contexts` est fermé en entrée : le front lit les écrans d'un site client dans
  les contextes du site d'attache. La lecture d'administrateur d'API Platform (`GET /api/categories`) est servie comme à un visiteur
  (401) ; la lecture publique `/api/category` reste ouverte.
- Le jeton d'entrée est rendu dans le corps de la réponse (le front l'envoie en `Authorization` à l'hôte du site visé) ; il vit 15 minutes.
- Le domaine propre du site reste réglable par l'agence (plan du propriétaire) ; la construction lui laisse aussi les pixels, les
  contenus intégrés et le texte des pages : une agence malveillante pourrait y capter les visiteurs (risque signalé au propriétaire).
- Hors de l'entrée et pour tous (défaut antérieur, signalé) : `PUT /api/entreprise/{id}` change la devise et les prix taxes comprises
  sans les verrous d'EasyAdmin (`SiteCurrencyService::assertChangeAllowed`, `TaxInclusiveGuard`).
- Les modules du site ne se changent pas en entrée tant que le forfait de l'agence n'y est pas relu.
- Clients et commandes ne s'importent pas en entrée : le commerçant les importe lui-même, ou le super administrateur
  (`docs/agences-et-import.md` § 3.5).

## 8. Lot AG-ASSIST : l'accès d'assistance d'une agence (16/11/2026, codé sous Opus, à faire vérifier par Fable)

Contrat validé par le chef de projet et le propriétaire (journal du 16/11/2026). Le **commerçant** (administrateur du site) ouvre UNE
section fermée à l'agence qui gère son site ; l'agence ne peut ni la demander ni la prolonger. Partie API ; la partie EasyAdmin (bouton
dans les sections fermées, garde de l'opérateur dans EasyAdmin) entre dans AG-3c sur le même mécanisme.

**Sections** (`App\Services\Agency\Assistance\AssistanceSections`, écrans du registre) : `commandes` (commandes, expéditions,
abonnements), `clients` (demandes, réservations), `caisse` (EasyAdmin), `stock`, `gestion` (toute la famille), `pertes` (toute la
famille, **consultation seulement** : `write` → 422 `ASSISTANCE_READ_ONLY`), `assistance` (tickets). `stock` et `caisse` valent pour EasyAdmin (mouvements de stock, caisse) et les écrans du stock : dans l'API, le stock se lit aujourd'hui par les statistiques, donc par la section `gestion`. Jamais demandables : paiement,
clés, courriel expéditeur, comptes, Mémoires Vivantes, Boussole.

**Routes du commerçant** (`SiteAssistanceController`, `ROLE_ADMIN` d'un compte DU site ; un opérateur extérieur ou toute entrée par
jeton : 403 `ASSISTANCE_FORBIDDEN`, et la garde du mode les ferme à l'agence, écran `site.assistance` de la famille `vente`) :
- `GET /api/site/assistance` → `{ assistance: [{ id, section, mode, state, agency, requestedBy, requestedAt, requestExpiresAt,
  startedAt, endsAt, closedAt, closedBy }] }` ; `state` : `waiting` | `active` | `ended` | `expired` | `closed` (calculé à la lecture).
- `POST /api/site/assistance` `{ section, mode?: read | write }` (défaut `read`) → 201 `{ assistance }` (+ `notified`) ; 422
  `ASSISTANCE_SECTION_INVALID` | `ASSISTANCE_MODE_INVALID` | `ASSISTANCE_READ_ONLY` ; 409 `ASSISTANCE_NO_AGENCY` (aucune agence vivante ne
  gère le site) | `ASSISTANCE_ALREADY_OPEN` (une demande vivante par section, garantie par un index unique partiel ; une demande échue
  est fermée d'abord). Le compte de la plateforme lit, mais ne demande ni ne ferme (403).
- `POST /api/site/assistance/{id}/close` → `{ assistance }` (`closed`, `closedBy`) ; 404 `ASSISTANCE_NOT_FOUND`.
- `GET /api/site/assistance/{id}/journal` → `{ journal: [{ at, account, action: assistance_read | assistance_write, method, path,
  status }] }` : chaque écran et chaque fiche consultés, chaque écriture.

**Route de l'agence** : `GET /api/agency/assistance` (site d'attache) → ses demandes `waiting` et `active`, avec `site`, `siteName`.

**Courriel** `assistance_request`, classé indispensable (jamais retenu), par l'expéditeur de la plateforme, à `contact_email` de la fiche
d'agence, sinon aux responsables (`ROLE_AGENCY`) de son site d'attache ; site, section, mode, demandeur, numéro ; lien vers « Tous mes
clients » du site d'attache (`/admin/ouvrir?screen=agence.clients`), sans jeton. Langue : celle du site qui demande.

**Fenêtre** : une heure à partir de la PREMIÈRE requête de l'opérateur dans la section (la demande `waiting` démarre alors, une seule
fois) ; une demande sans requête dans les 24 h expire. Aucun minuteur.

**Garde** (`EntryModeGuardSubscriber::assistanceRefused`, après la double relecture) : pour un écran hors construction (raison `family`),
une assistance vivante (en cours avant en attente ; pour une écriture, « modifier » d'abord) de L'agence de l'opérateur, qui gère ENCORE ce
site, pour une section de l'écran, sert la requête. Lecture : toujours, hors comptes et rôles, exports de fait (`/api/newsletter`, tout
`/fichier` : rapport, justificatif d'un document) et `CLOSED_PATHS` (clés, export, Stripe, modules) → `assistance_closed`. Écriture : seulement en `write` (sinon
`assistance_read_only`) ET si la route est dans la **liste blanche** de la section (`AssistanceSections::WRITES`, fermé par défaut ;
témoin `tests/Fixtures/ecrans/assistance-ecritures.json`, essai `testEveryWriteOfASectionIsListed`) : commandes = préparer un colis (jamais
annuler une vente, qui la rembourse, ni acheter une étiquette, capturer, rembourser) ; clients = la fiche d'un contact (`PUT`, la fiche entière) ou le statut d'une demande
(jamais supprimer ; confirmer un rendez-vous exige un compte du site) ; gestion = tout sauf la console IA, les fils d'action, les documents lus par l'IA, les
rapports, les avis rédigés (unités IA du commerçant), le lexique, les préférences de notification et les réglages ; pertes = rien. La
fenêtre d'une heure ne démarre que sur une requête ACCEPTÉE. La réponse de la section est servie entière. L'auteur au journal devient
« Agence X, prénom, pendant l'assistance n° N » (`ExternalOperator::duringAssistance`). Sections demandables aujourd'hui par l'API :
commandes, clients, gestion, pertes ; caisse, stock, assistance → 422 `ASSISTANCE_SECTION_UNAVAILABLE` jusqu'à AG-3c.

**Trace** : une ligne par requête couverte, ACCEPTÉE OU REFUSÉE, écrite dans `content_audit_log` AVANT de servir (`resource`
`external_access`, `action` `assistance_read` | `assistance_write` | `assistance_refused`, `after_state` : numéro, section, écran, méthode,
chemin, paramètres, raison d'un refus, puis le statut de la réponse ajouté après) ; si elle ne s'écrit pas, la requête n'est pas servie
(503 `SERVICE_UNAVAILABLE`). Lue par la route `journal` et par la page « Accès extérieurs » d'EasyAdmin (60 lignes d'assistance au plus,
pour garder la place des connexions et des entrées). L'agence ne lit que le prénom du demandeur, et seulement pour les sites qu'elle gère
encore ; une demande d'une autre agence est fermée à la demande suivante (raison `agency`).

**Base maître** : table `assistance_grants` (`ops/sql/assistance_master.sql`, script `scripts/migrate_master_v2_assistance.sh <SQL du
worktree de construction>` AVANT l'échange). Bases des sites : rien. Essai : `tests/Functional/Security/AssistanceTest.php`.

## 9. Lot AG-3c : EasyAdmin par mode (17/11/2026, codé sous Opus, à faire vérifier par Fable)

Contrat validé par le propriétaire le 16/11/2026 (classement des sections, sélecteur du super administrateur, laissez-passer par formulaire POST).

### 9.1 Une session pour tous les sites

EasyAdmin sert tous les sites depuis le même domaine : le site actif est choisi par `?tenant=<code>` et gardé dans la session
(`_active_admin_tenant`). Entrer dans le site d'un client REMPLACE donc, dans cette session, la connexion du site d'attache ; « Quitter »
vide la session et renvoie à la connexion du site d'attache (`/?tenant=<site d'attache>`).

### 9.2 Le sélecteur et l'entrée

- `GET /admin/contextes` (menu « Les sites de mes clients » ou « Tous les sites ») : la même liste que `/api/me/contexts` ; agence = ses
  sites gérés, super administrateur = tous les sites ; un site seul n'a pas de sélecteur. Jamais pendant une entrée.
- `POST /admin/contextes/entrer` (jeton CSRF, champ `site`) : compte d'agence ou compte de la plateforme du site d'attache, **code validé
  depuis moins de 12 heures** (`OtpSession::validatedSince`, heure posée à la validation du code ; une session validée avant ce lot redemande
  le code). Un compte d'agence sans code (ni activé sur le compte, ni imposé par `ADMIN_OTP_REQUIRED`) n'entre pas. L'entrée est ouverte par
  `EntryService::enterFromAdmin` (mêmes vérifications du lien et mêmes traces que l'API : `site_entries` et journal du site visé), pour
  **1 heure** (`ADMIN_TTL`).
- Le serveur rend une page qui porte un **laissez-passer** (`AdminPassService`) dans le CORPS d'un formulaire POST auto-soumis vers
  `/admin/entree?tenant=<site visé>` : jamais dans une adresse ; page `no-store`, `no-referrer`. Le laissez-passer est signé (HMAC, clé du
  noyau), vaut 60 secondes, pour ce site seulement, une seule fois (marque `SET NX` dans Redis ; Redis injoignable = refus).
- `POST /admin/entree` : authentificateur `AdminPassAuthenticator` du pare-feu `main`. Mode agence : la session porte l'**opérateur
  extérieur** (aucun compte chez le client). Mode super administrateur : la session porte LE compte de la plateforme du site visé, sans
  reconnexion ni code (la validation du site d'attache vaut, à son heure d'origine). Refus : page HTML 403 avec la raison (`pass_signature`,
  `pass_expired`, `pass_site`, `pass_replayed`, `pass_malformed`, `otp`, `entry`).
- À chaque requête de la session, l'entrée est **relue** (`AdminEntrySubscriber`, `TenantUserProvider::refreshUser` pour l'opérateur) :
  fermée, échue, agence suspendue, site détaché, rôle retiré → session vidée, retour à la connexion du site d'attache. Changer de site par
  `?tenant=`, se déconnecter ou « Quitter » ferme l'entrée (`left`, `logout`).

### 9.3 Ce que voit l'opérateur

- La garde du mode (`EntryModeGuardSubscriber`) couvre `/admin` : une requête se classe par son écran CRUD (`crudControllerFqcn`) ou sa
  route (`routeName`), jamais par le chemin ; même règle que l'API (famille construction, module actif, `CLOSED_PATHS`) ; refus en page
  HTML 403. Ses seules sorties hors des écrans : `/admin/entree/quitter` et `/logout`.
- L'accueil (tableau des ventes) est remplacé par une page de construction (son entrée, son échéance, « Quitter »).
- Champs sensibles retirés des listes, fiches et formulaires (`AdminFieldGuard`, décorateur de l'autorisation des champs d'EasyAdmin) :
  coût d'achat, coefficient et commande fournisseur d'un article ; courriel, devise, fuseau, découpage des périodes, adresses d'alerte et
  mode Stripe de la fiche Entreprise ; candidatures, références de commandes et de paiements, lignes de taxe des commandes, questions posées
  à l'IA.
- Réglages sensibles sortis de la construction, nouvelle famille `securite` : `admin.courriel` (serveur d'envoi), `admin.devises`,
  `admin.cles` (`/admin/app-keys`), `admin.ia-reglage` (consignes et crédits IA). L'historique de l'IA reste ouvert (consommation).
- Pendant une assistance ouverte par le commerçant, la section couverte s'ouvre aussi dans EasyAdmin, EN LECTURE ; les écritures d'EasyAdmin
  restent fermées en mode « modifier » (la liste blanche ne connaît que les routes de l'API).

### 9.4 Pour tous les comptes

- **Menu piloté par le registre** (`ContextService::currentScreens`) : une entrée n'est montrée que si son écran est ouvert à ce compte sur ce
  site (famille du mode, module actif = droit ET interrupteur, rôle). DIFFÉRENCE : un module éteint ou sans droit ne montre plus ses entrées.
- Tuile « Modules disponibles » sur l'accueil.
- « Assistance de mon agence » (`/admin/assistance`, écran `site.assistance`) : le commerçant demande, suit et ferme l'assistance ; depuis
  EasyAdmin, toutes les sections se demandent (caisse, stock et assistance aux clients compris).

### 9.5 Après la double relecture (17/11/2026)

- **Une requête `/admin` se juge sur ce qu'EasyAdmin exécute** (`EntryModeGuardSubscriber::adminRequest`) : `routeName` avec
  `crudControllerFqcn` est refusé (`ambiguous` : EasyAdmin exécutait la route sous l'étiquette de l'écran) ; une page nommée se juge sur SON
  chemin (`router->generate`), règles par chemin, corps, ouverture de la boutique et assistance comprises, et seulement une route d'EasyAdmin
  (`/admin/…`, sinon `surface`) ; une action par lot est refusée à l'opérateur (`batch`).
- **Assistance dans EasyAdmin** : seules une liste ou une fiche (`index`, `detail` en GET) lisent ; toute autre action (rembourser, confirmer,
  exporter, même en GET) compte comme écriture, donc refusée en consultation et, faute de liste blanche, en modification.
- **Suppression par lot** (`BaseTenantCrudController::batchDelete`, pour TOUT compte) : seule l'entité de l'écran ; l'entité lue dans le corps
  du formulaire ne passe plus.
- **Recherche et tri** : pour l'opérateur, les champs fermés de l'écran ne se cherchent ni ne se trient (`AdminFieldGuard::hidden`).
- **Champs fermés de la fiche du site** (décision du chef du 17/11/2026, principes « jamais la caisse » et « jamais un chiffre ») : UNE liste
  partagée par `PUT /api/entreprise` et la fiche d'EasyAdmin (`SiteSheetClosedFields`) : l'onglet Caisse entier (propriétés `pos…`), l'adresse
  des commandes et des demandes, la devise, le fuseau, le découpage des périodes, les adresses d'alerte, la conservation des données, les comptes
  comptables, l'appartenance aux applications, le niveau des courriels. Rien ne change pour le commerçant ni pour le super administrateur.
  Aussi fermés dans EasyAdmin : le résumé, l'issue et la clé de mémoire des demandes à l'IA.
- **Solde prépayé d'expédition** (décision du chef) : jamais servi à l'agence (`balanceCents` retiré de `/api/site/shipping` et de
  `/api/site/features`, carte masquée sur `/admin/livraison`) ; le commerçant le lit toujours.
- **Console de l'assistant** : jamais servie à l'opérateur.
- **Laissez-passer lié à la session** qui l'a demandé (`sid`, HMAC de l'identifiant de session) : volé, il ne sert pas ailleurs ; il ne
  connecte pas non plus le navigateur d'un tiers (`pass_session`).
- **Super administrateur entré** : un bandeau sur l'accueil dit l'entrée et porte « Quitter » ; « Quitter » et la déconnexion ne sont plus
  refusés par la règle des écritures sur un compte.
- Redis du cache : `noeviction`, la marque d'usage unique n'est jamais évincée.

Essai : `tests/Functional/Security/AdminEntryTest.php` (5 essais ; 7 mutations des correctifs, toutes tuées).

### 9.1 Lot BANDEAU (18/11/2026, demande du propriétaire)

Pendant une entrée, TOUTES les pages d'EasyAdmin (mise en page surchargée `templates/bundles/EasyAdminBundle/layout.html.twig`, bloc
`content_header_wrapper`) et les pages d'administration hors d'EasyAdmin (`base_admin.html.twig`) portent le bandeau
`admin/entree/_bandeau.html.twig` : « Vous êtes dans <nom du site> (<adresse>) en tant que <Plateforme, prénom | Agence X, prénom | Groupe X,
prénom> », le site d'attache, l'échéance, et « Quitter » (fermeture de l'entrée, retour à la connexion du site d'attache). Données :
`App\Twig\AdminEntryBannerExtension` (`admin_entry_banner()`), lues de l'entrée de la session et de la base maître ; jamais une adresse de
courriel. L'ancien bandeau, posé sur le seul accueil, est retiré.

**Lot RETOUR (18/11/2026, lot à risque, règles du chef)** : le bouton du bandeau devient « Revenir à <site d'attache> ». À l'ouverture de
l'entrée (`/admin/contextes/entrer`), la SESSION seule garde le retour (`AdminEntrySession::RETURN_KEY` : compte d'attache, son adresse, l'heure
d'origine de son code, l'empreinte de son mot de passe, le numéro de l'entrée ; jamais dans un paramètre ni un cookie lisible). « Revenir »
(POST `/admin/entree/quitter`, jeton CSRF) ferme l'entrée puis `EntryService::returningAccount` relit le compte SUR SON SITE : même numéro,
même adresse, mot de passe inchangé, contrôles du compte (`UserChecker`), encore autorisé à entrer (rôle d'agence ou compte de la plateforme) ;
la session le reprend alors sous un NOUVEL identifiant, avec l'heure d'origine de son code (jamais rajeunie), et une ligne `return` est
écrite au journal des deux sites. Sinon : session vidée, retour à la connexion du site d'attache. Le retour sert une fois (lu et retiré).
Essais : `AdminEntryTest::testReturningHomeKeepsTheSessionOnlyForTheSameUnchangedAccount` (retour normal, compte désactivé, mot de passe
changé, GET refusé, deux onglets).

**Correctif de sécurité (relecture neuve de RETOUR, 18/11/2026, défaut EN LIGNE depuis l'étape 100)** : quand une entrée d'EasyAdmin se fermait
en vidant la session (« Quitter », entrée refusée ou fermée, session qui quitte le site visé), le jeton restait dans `TokenStorage` et le
pare-feu le réécrivait dans la session neuve ; le compte de la plateforme, qui porte le même mot de passe sur chaque site, pouvait ainsi
rester connecté au site d'attache même désactivé, avec un seul code. Corrigé : le jeton est retiré AVANT tout vidage de session
(`AdminEntryController::leave`, `AdminEntrySubscriber`, 4 endroits) ; essai : un retour refusé ramène à la connexion, jamais à la page du code.
Autres corrections de la même relecture : aucune adresse au journal (« Plateforme, prénom »), session neuve au retour, lien de l'agence relu
sans cache, retour refusé si une trace manque.
Essai : `AdminEntryTest::testTheBannerNamesTheSiteAndTheOperatorOnAListAndADetailPage` (liste, fiche, page nommée).

## 10. Lot AG-3d : « Tous mes clients » et « Taxes vérifiées par le commerçant » (18/11/2026, contrat validé par le chef)

### 10.1 « Tous mes clients »

- `GET /api/agency/clients` (compte d'agence, depuis son site d'attache ; écran `agence.clients`) → `{ clients: [...], canEnter }`. Un tableau
  TECHNIQUE, lu de la base maître et de l'état de chaque site, SANS y entrer (`AgencyClientsService`). Par site géré : code, nom, adresse WEB,
  origine, rattachement ; état (construction / ouvert, étendue, date d'ouverture) ; modules actifs ; liste d'ouverture en nombres
  (`checklist: { blocking, improvements, canOpen }`) ; invitation de l'administrateur (état `pending | accepted | expired | not_sent` et dates,
  JAMAIS le destinataire) ; essais qui finissent dans les 30 jours ; assistances en attente ou en cours ; dernière entrée de l'agence (date,
  prénom). Jamais un chiffre d'affaires, un nombre de commandes, de clients ou d'avis, un montant, un solde (liste blanche `KEYS`, sondée par
  l'essai). Agence suspendue : lisible, `canEnter` faux ; agence fermée : 403.
- Chaque ligne lit une base de site : gardée 5 minutes dans le cache partagé (étiquette du site), vidée par l'entrée de l'agence, l'ouverture
  ou la fermeture du site et le geste de module de l'agence ; les essais et l'invitation se relisent à l'échéance. Mesure sur l'agence
  d'essai : une ligne en 113 ms sans cache, 5 ms avec (50 clients : environ 5 s à froid, une fois toutes les 5 minutes).
- EasyAdmin : pour l'agence, le sélecteur (`/admin/contextes`) est ce tableau, avec « Entrer » par ligne (sans « Entrer » si l'agence est
  suspendue).

### 10.2 « Taxes vérifiées par le commerçant »

- Fiche du site : `taxes_verified_at`, `taxes_verified_by` (prénom) ; migration `Version20270130300000`, script
  `scripts/migrate_all_v2_taxes_verifiees.sh`.
- `POST /api/site/taxes/verify` → 200 `{ taxes: { verified, at, by } }` ; 403 `TAXES_VERIFY_FORBIDDEN` à l'opérateur d'une agence, à toute
  entrée par jeton et au compte de la plateforme (écran `site.taxes`, famille ventes : la garde du mode le ferme aussi à l'agence). Écrit au
  journal du site. Dans EasyAdmin : `/admin/taxes/verifier`, signalé en tête de l'écran Taxes.
- TOUTE écriture d'une taxe la fait tomber : un déclencheur de la base sur `tax` (`taxes_verification_falls`, par instruction), qui couvre
  EasyAdmin, Doctrine, les requêtes directes, les imports, la console de l'IA et les valeurs d'un site neuf.
- Liste d'ouverture (`GET /api/site/storefront/checklist`, vue de l'agence comprise) : une ligne `taxes` `{ ok, blocking: false, message }`
  (« Taxes vérifiées par <prénom> le <date> » ou « Taxes à vérifier par le commerçant ») ; elle avertit sans bloquer, et n'existe que quand
  la boutique est allumée, comme les autres lignes.

Essai : `tests/Functional/Platform/AgencyClientsTest.php`.

## 11. Lot IA-B : l'assistant de l'agence (18/11/2026, contrat validé par le chef)

- `POST /api/agency/assistant` `{ messages, locale? }` (compte d'agence, sur son site d'attache) → `{ reply, actions: [{ id, tool, input, summary,
  needs }], links, usage, credits }` ; `POST /api/agency/assistant/actions/{id}/confirm` (responsable de l'agence seulement, 15 min) → 202.
  `AgencyAssistantService`.
- Lectures : « Tous mes clients » (sans chiffre), les demandes de création (sans destinataire), la disponibilité d'une adresse de site, l'aide.
  Proposition : `propose_site` (questions une à une, puis un RÉCAPITULATIF) ; la confirmation passe par le chemin d'AG-2 (`POST /api/agency/sites` :
  limites, file, refus repris tels quels). `open_client` mène à « Entrer » : l'assistant ne lit jamais un site client.
- **Adresse de l'administrateur à inviter** : jamais par le modèle. Elle se saisit dans le récapitulatif (`adminEmail` du corps de la
  confirmation, validée par le serveur) ; le modèle voit `adminEmailProvided: false`. Toute adresse de courriel, dans un message de l'agence,
  un résultat d'outil ou le tour du modèle renvoyé au suivant, est masquée (`[adresse]`) avant l'envoi.
- **Unités** : celles du site d'attache de l'agence, avec son plafond mensuel ; épuisées : 402 `AI_CREDITS_EXHAUSTED`, jamais un débit sur un
  client.
- Agence suspendue : les outils de proposition ne sont pas donnés au modèle, et refusés s'il les invente. Textes lus = données ; aucune écriture
  sans confirmation ; trace `agency_assistant` / `confirm` au journal du site d'attache (« par l'assistant, confirmé par <prénom> »).

Essai : `tests/Functional/Platform/AgencyAssistantTest.php` (5 mutations des filtres, toutes tuées).

## 12. Lot G-1a : le multi-boutiques, groupes et invitations (18/11/2026, contrat validé par le chef, 4 ajouts)

Plan : `docs/plan-entree-et-groupe.md` § 4. G-1b (entrée du directeur dans une boutique du groupe) suit, relu avec G-1a.

- **Module** `module.multi_shop` (colonne `entreprise.is_multi_shop_active`, éteinte par défaut ; `ModuleAccessService::MULTI_SHOP`). Il faut le
  module au SIÈGE pour créer le groupe et inviter ; une boutique invitée n'en a pas besoin.
- **Base maître** (`ops/sql/groups_master.sql`, script `scripts/migrate_master_v2_groups.sh`) : `shop_groups` (siège, état actif | dissous,
  `max_shops` 10), `shop_group_sites` (origine `head` | `created` | `associated`, un lien ouvert par site au plus : index unique),
  `shop_group_invitations` (14 jours), `shop_group_events` (journal). Quitter ne supprime rien (`left_at`).
- **Routes** (administrateur DU site ; entrée par jeton, agence comprise, et, pour écrire, compte de la plateforme : 403 `GROUP_FORBIDDEN`) :
  - `GET /api/group` → `{ group: null | { id, code, name, head, role: head | shop, maxShops }, shops: [{ code, name, origin, joinedAt }], canCreate }` ;
  - `POST /api/group` « Passer en multi-boutiques » → 201 ; 403 `MODULE_INACTIVE` ; 409 `GROUP_ALREADY_MEMBER` ;
  - `POST /api/group/invitations` `{ site }` → 202 `{ asked: true, expiresInDays: 14 }`, **la même réponse** que le site existe ou non, soit le
    siège, soit déjà dans un groupe ou déjà invité : le tri se fait en file (`SendGroupInvitationMessage`, file `email`), la raison d'un refus
    reste au journal du groupe (`invitation_skipped`). Limiteur : 10 demandes par jour et par groupe, 429 `GROUP_INVITE_LIMIT` ; 403
    `GROUP_HEAD_ONLY`, `MODULE_INACTIVE` ;
  - `POST /api/group/leave` `{ shop? }` : la boutique quitte, ou le siège retire une boutique ; le siège seul dissout (409 `GROUP_NOT_EMPTY`
    tant qu'il reste une autre boutique) ;
  - `GET /api/site/group-invitations` → `{ invitations: [{ id, group, head, expiresAt, consent, consentVersion }] }` ;
    `POST /api/site/group-invitations/{id}/accept` `{ consent: "g1-v1" }` → le groupe ; 422 `GROUP_CONSENT_REQUIRED` sans la version exacte ;
    404 `GROUP_INVITATION_NOT_FOUND` (inconnue, d'un autre site, échue, traitée, groupe dissous) ; 409 `GROUP_ALREADY_MEMBER` | `GROUP_SHOP_LIMIT` ;
    `POST …/{id}/refuse` → 204.
- **Consentement** (texte du chef, `GroupService::consent`) : « Le directeur du groupe X pourra entrer dans votre boutique et y agir ; chaque geste
  sera tracé ; vous pouvez quitter le groupe à tout moment. » Dit dans le courriel et dans la liste des invitations ; accepter sans sa version
  est refusé : jamais d'ajout silencieux.
- **Courriel** `group_invitation` (indispensable, `NotificationCatalog`) aux seuls administrateurs vérifiés du site invité, jamais au compte de
  la plateforme ; sans lien : la réponse se donne dans l'espace d'administration.
- Écran du registre `groupe` (famille vente) ; l'agence ne voit jamais le groupe (lien séparé, décision du propriétaire du 18/11).

Essai : `tests/Functional/Group/GroupApiTest.php`.

### 12.1 Lot G-1b : l'entrée du directeur dans une boutique du groupe

- **Qui** : un administrateur du SIÈGE (ni le compte de la plateforme, qui a son mode, ni un simple compte), groupe actif, module actif au
  siège, vers une boutique ouverte du groupe. Même chemin que l'agence : `POST /api/me/contexts/enter` (jeton de 15 minutes, session vivante
  exigée) et, dans EasyAdmin, le sélecteur « Les boutiques du groupe » (`/admin/contextes`, code validé depuis moins de 12 heures, laissez-passer
  d'une minute). Mode `group` dans `site_entries` (`group_id`, nom du groupe dans `agency_name`) ; l'objet est `GroupOperator`, jamais un
  compte de la boutique. Si le compte a aussi un rôle d'agence, une boutique du groupe s'ouvre en mode groupe (deux liens séparés).
- **Ce qu'il a** : tout le site, comme un administrateur, sauf (`EntryModeGuardSubscriber::groupDecision`, liste figée dans
  `tests/Fixtures/ecrans/entree-groupe-fermees.json`) : Stripe et le terminal de paiement (`payment`), les remboursements et les gestes
  d'argent d'une commande (annuler, capturer, retour ; retours et échanges de la caisse ; caution rendue : `refund`), les gestes de module
  (`modules` : ils changent ce que la boutique paie, ajout tranché ici), le compte (`@compte` : `account`). Les routes du groupe et la
  vérification des taxes refusent toute entrée (gestes du commerçant). Refus : 403 `MODE_FORBIDDEN` avec `reason`.
- **Traces** : l'entrée au journal de la boutique (« Groupe X, prénom », canal `entry_group`) ; chaque écriture réussie (canal `group_write`,
  méthode et chemin), lues par « Accès extérieurs » (`PlatformAccessJournal::groupWrites`).
- **Fin** : la boutique quitte le groupe (ou le siège la retire, ou dissout) → ses entrées se ferment TOUT DE SUITE (`EntryService::endForGroup`,
  cache vidé) : la requête suivante reçoit 401 `ENTRY_ENDED`. Relu à chaque requête (une minute au plus) : rôle d'administrateur perdu
  (`role`), module éteint au siège (`module`), groupe dissous ou siège changé (`group`).
- **Contextes** (`GET /api/me/contexts`) : clé `group` dans toute réponse (null hors groupe) ; au siège, mode `group`, le siège (`home: true`)
  puis les boutiques (`home: false`) ; dans une boutique membre, mode `single` et `group: { id, name, role: "shop" }` pour son administrateur.
- Sans Stripe ni remboursement au premier lot ; « le directeur peut rembourser », réglage par boutique allumé par son administrateur, sera un
  lot à part (décision du propriétaire du 18/11).

Essai : `tests/Functional/Group/GroupEntryTest.php` (dont une sonde de toutes les lectures sans paramètre : aucune panne pour le directeur).

### 12.2 Relectures, décisions et garde-fous de G-1 (18/11/2026)

- **Deux relectures neuves** (passes 1 et 2), constats corrigés : comptes et rôles, réglages sensibles (famille sécurité), Mémoires Vivantes,
  Boussole, abonnements, assistant IA du site, clés des applications, domaine, terminal et postes de caisse, gestes de module, motifs par
  PRÉFIXE, dissolution et limiteur sous verrou (limite comptée par SIÈGE), une seule invitation ouverte par site.
- **Décisions du chef** : prix, promotions, coupons, dépenses saisies, actions de conversation, trésorerie, budgets, ouverture de la boutique :
  PERMIS (tracés) ; IA FERMÉE au directeur (routes, et point unique `LandingAiQuotaService::reserve` : les unités de la boutique ne sont jamais
  débitées ; débit au siège dans un lot suivant) ; import des clients finaux et leurs invitations FERMÉS ; adresse qui reçoit les commandes et
  celle des demandes FERMÉES dans la fiche du site (avec la devise et le terminal) ; 60 s au plus pour le retrait du rôle ou du module.
- **Garde-fou structurel** (`GroupWritesClassificationTest`, table `tests/Fixtures/ecrans/entree-groupe-ecritures.json`) : toute écriture de
  l'API et d'EasyAdmin, tout écran CRUD et tout outil de l'assistant est CLASSÉ pour le directeur (permis, fermé avec sa famille, ou écran en
  lecture seule `GROUP_EA_READ_ONLY`) ; un ajout non classé fait échouer la suite.
- **Garde-fou des identifiants** (`RouteIdRequirementTest`) : toute route de l'API à identifiant a une exigence (63 routes `\d+` ajoutées :
  un identifiant non numérique y répond 404) ; exceptions écrites (API Platform, Mémoires Vivantes, tâche textuelle de l'assistant de page).
- **RETOUR** vaut pour le directeur (« Revenir au siège »). Mutations : 14/15 tuées (G3 : la liste des actions d'EasyAdmin est vérifiée deux
  fois, couper l'une ne rouvre rien).

## 13. Lot G-2 : « Toutes les boutiques » (18/11/2026, contrat validé par le chef, 3 ajouts)

Le directeur d'un groupe lit plusieurs écrans de la gestion pour toutes ses boutiques à la fois, en lecture seule. Code :
`src/Services/Group/AllShops/` (`GroupScope`, `GroupScopeSubscriber`, `AllShopsReader`, `AllShopsMerger`).

- **Mode** : en-tête `X-Group-Scope: all`, sur le site du SIÈGE, par un administrateur du siège connecté avec SON compte (jamais un
  opérateur entré, jamais un compte de la plateforme). Groupe actif, siège, module Multi-boutiques actif au siège : relus à chaque requête.
  Sinon 403 `GROUP_SCOPE_FORBIDDEN`. Valeur autre que `all` : 400 `GROUP_SCOPE_INVALID`.
- **Lecture seule** : avec l'en-tête, toute méthode autre que GET répond 403 `GROUP_SCOPE_READ_ONLY`. Une route hors de la liste blanche,
  ou un paramètre propre à un site (`sections`, `periodId` des statistiques), répond 400 `GROUP_SCOPE_UNSUPPORTED` : jamais les seules
  données du siège servies en silence. Garde : `GroupScopeSubscriber`, priorité 6 (après le pare-feu et la garde d'entrée).
- **Routes** (`GroupScope::ROUTES`, lues par `/api/me/contexts` dans `group.allShops`) : `GET /api/gestion/statistiques` (sans `sections`
  ni `periodId`), `GET /api/gestion/journee`, `GET /api/gestion/pertes` (analyse jamais lancée : `analyse=0` imposé).
- **Réponse** : `{ scope: 'group', group { id, name }, generatedAt, truncated, totals [ par devise ], shops [ { site, name, head, status,
  currency, timezone, data } ] }`. `status` : `ok`, `module_off` (la boutique n'a pas le module de la route ou de la gestion), `error`
  (code `SHOP_UNAVAILABLE` seul, le détail au journal du serveur), `timeout`, `skipped`. `data` est la réponse de la route lue seule dans
  cette boutique, telle quelle (garde-fou d'identité dans `GroupAllShopsTest`) ; pour les pertes, une liste blanche de champs.
- **Totaux par devise**, jamais deux devises additionnées ; seules les boutiques `ok` y entrent (`totals[].shops`).
  - Statistiques : les sommes brutes de chaque boutique (ventes brutes, retours, coût, coût inconnu, commandes) repassent par
    `GestionStatisticsService::metrics()` : taux de marge, panier et taux de retour recalculés, jamais moyennés. Points du graphique
    réunis par clé. Une comparaison absente d'une boutique (an dernier, valeur passée du stock) : le total ne porte que celles qui l'ont,
    remarque `PARTIAL_COMPARISON`. Remarques réunies par code avec leurs boutiques ; le texte n'est gardé que s'il est le même partout.
  - Journée : sommes champ par champ, listes réunies par canal et par moyen ; comparaison au même jour de semaine recalculée sur les
    sommes, `asOf` nul ; plus ancienne commande à expédier ; trésorerie : somme des soldes et état le plus grave ; instants de lecture nuls.
  - Pertes : comptes et montants sommés ; postes de caisse jamais réunis (propres à chaque boutique). Par poste et par jour, jamais
    par personne : la part ne porte ni la notice aux salariés ni aucun nom (`AllShopsMerger::lossPart`).
- **Lecture boutique par boutique** dans la requête (`AllShopsReader`) : changement de site, puis retour au siège dans un `finally`, même
  si une boutique lève une exception. Chaque boutique est lue dans une transaction `READ ONLY` annulée à la fin : une lecture qui tenterait
  d'écrire échoue (statut `error`), elle n'écrit rien. Une erreur de validation (422) remonte telle quelle.
- **Délai et budget** : `statement_timeout` local de 3 s par boutique (ou ce qui reste du budget) ; au-delà, `timeout`. Budget de la
  réponse : 15 s, les boutiques restantes en `skipped`. Plafond : 20 boutiques (`truncated`).
- **Cache** (statistiques et journée ; jamais les pertes) : la part d'une boutique, 5 minutes, sous les étiquettes du site et de sa base.
  La clé porte le jour de la boutique et l'EMPREINTE des tables lues (`GroupScope::TABLES`, compteurs d'écritures de PostgreSQL) : une
  écriture dans une table lue, par Doctrine ou en SQL, périme la part dès que PostgreSQL l'a comptée (à la fin de la requête qui écrit ;
  au plus 10 s pour un worker qui garde sa connexion). Garde-fou `GroupScopeReadTablesTest` : toute table lue par la route doit être
  dans la liste.
- Mesure (environnement d'essai, 3 boutiques, noyau en débogage) : statistiques du mois 265 ms à froid, 121 à 141 ms en cache (193 ms
  seule) ; journée 377 ms à froid, 114 ms en cache (161 ms seule) ; pertes 151 ms (jamais en cache).

## Tenir cette fiche à jour

À chaque écran, famille ou liste hors écran ajoutés ; à chaque champ sensible trouvé ; à chaque mode nouveau.
