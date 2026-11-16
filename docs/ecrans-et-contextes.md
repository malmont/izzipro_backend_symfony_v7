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
autre, ni vers un troisième site (403). Limite : 30 entrées par 10 minutes par compte.

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

À chaque requête, le lien est RELU (`EntryService::check`, cache d'une minute au plus) : entrée fermée, échéance passée, compte du site
d'attache supprimé ou d'une autre adresse, rôle retiré, agence suspendue ou fermée, site détaché → 401 `ENTRY_ENDED` dès la requête
suivante, et l'entrée est fermée au journal avec sa raison. La DÉCONNEXION du site d'attache (`POST /api/logout`) ferme aussitôt toutes
les entrées du compte.

### 7.3 La garde du mode agence (`EntryModeGuardSubscriber`), fermée par défaut

1. La route se classe par ce registre (`classify`, sur le chemin décodé). Ouverte seulement si son écran est de la famille
   **construction** et que son module est actif chez le client. Route non rangée, liste hors écran, autre famille, module éteint,
   chemin hors de `/api/` : 403 `MODE_FORBIDDEN` (`reason` : `unclassified`, `off_screen`, `family`, `module`, `surface`).
2. Les **lectures du site public** (`@public`, GET) sont servies comme à un visiteur : l'authentification est retirée (sinon les rôles
   d'administrateur de l'opérateur y ouvriraient ce qu'un administrateur lit). La garde de la CONSTRUCTION, elle, le traite comme un
   compte d'équipe (`StorefrontViewer`) : il voit la vitrine d'un site encore fermé. La garde du mode reste au-dessus.
3. Fermés même dans la construction (précisions du chef) : clés d'API et d'activation (`keys`), export (`export`), paiement
   (`payment`), changement des modules du site (`modules` : le forfait de l'agence n'y est pas encore relu) ; l'écriture du coût
   d'achat et du coefficient d'un article (`cost`) ; l'import des clients et des commandes (`kind`). Comptes et rôles du site, Stripe et
   paiements, exports, pertes sont d'autres familles : fermés par la règle 1.
4. La réponse d'un écran de construction perd ses **champs sensibles** (§ 5, par suffixe du chemin du champ) ; la liste des imports ne
   garde que les imports du catalogue.

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
- Les modules du site ne se changent pas en entrée tant que le forfait de l'agence n'y est pas relu.
- Clients et commandes ne s'importent pas en entrée : le commerçant les importe lui-même, ou le super administrateur
  (`docs/agences-et-import.md` § 3.5).

## Tenir cette fiche à jour

À chaque écran, famille ou liste hors écran ajoutés ; à chaque champ sensible trouvé ; à chaque mode nouveau.
