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
| `vente` | commandes, paiements, caisse, expéditions, abonnements, mouvements de stock, **assistant du site**, **journal des modifications**, import des commandes |
| `clients` | comptes, demandes reçues, candidatures, avis, réservations, infolettre, import des clients |
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
| `@public` | lectures et envois d'un visiteur ou d'un client : catalogue, pages, panier, paiement, formulaires |
| `@compte` | connexion et compte de la personne connectée, dont `GET /api/me/contexts` |
| `@apps` | démarrage des applications : recherche du site, activation, profil, thème |
| `@caisse` | application de caisse |
| `@memoires`, `@boussole` | Mémoires Vivantes / Living Memory, espace des entreprises de la Boussole |
| `@boitier` | boîtier de la prévention des pertes |
| `@technique` | état du serveur, webhooks, fichiers, synchronisation du front, documentation d'API Platform |
| `@ancien` | anciennes pages de la boutique servies par le backend |

Les écrans CRUD d'EasyAdmin passent tous par l'adresse `/admin` : `ScreenRegistry::ADMIN_CRUDS` range chaque contrôleur CRUD par
son nom court.

### Tenir le registre

`tests/Functional/Screens/ScreenRegistryTest.php` relit **toutes les routes** de l'application (API Platform comprise) et **tous les
écrans CRUD** d'EasyAdmin :

- une route rangée nulle part fait échouer l'essai ; une règle qui ne range plus rien aussi ;
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
l'attache d'une agence ou qu'une agence le gère. Un site détaché sort de la liste ; une agence fermée n'ouvre plus le mode agence.

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
            modules: ["module.boutique", …],        // clés des modules actifs sur ce site
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
| `admin.stock` | mouvements de stock | l'historique des mouvements dit les ventes |
| `admin.utilisateurs` | comptes, adresses | l'agence ne crée pas de comptes d'équipe chez son client |

## 5. Champs sensibles des écrans de construction (`ScreenRegistry::SENSITIVE`)

Un écran de construction peut rendre, au milieu du reste, ce qu'une agence ne doit pas lire. Ce lot les NOMME (inventaire du
16/11/2026, `var/reprise/inventaire-champs-sensibles.md`) ; la garde d'AG-3b les retirera des réponses du mode agence (`fields`) et
fermera ce qui ne se filtre pas (`closed`).

| Écran | Champs à retirer | À fermer |
|---|---|---|
| `catalogue` | `purchasePrice`, `coefficientMultiplier`, `commande` ; dans le refus de suppression `PRODUCT_IN_USE` : `uses.sales`, `uses.purchaseLines`, `uses.bookings` | |
| `admin.catalogue` | colonnes « Prix d'achat », « Coefficient », « Dernière commande fournisseur » | |
| `admin.accueil` | `totalSales`, `totalOrders`, `totalCustomers`, courbes, dernières commandes (nom, courriel, montant) | la console flottante de l'assistant |
| `admin.entreprise` | compte des paiements par carte de la page Configuration ; chiffres des étapes de la gestion sur « Mon site » | créer une clé pour les applications `admin` et `pos` |
| `admin.ia`, `pages.ia` | `promptExcerpt` : début des questions posées à la console de gestion | |
| `site.reglages` | `charges` de `/api/site/shipping` (achats d'étiquettes par commande) ; `figures` de `/api/site/readiness` | `POST /api/app-keys` pour les applications `admin` et `pos` |
| `imports` | `summary.orders`, `report.orders`, `result.cancel.kept[].reason` | les imports `kind=customers` et `kind=orders` |

Le coût d'achat et le coefficient des fiches produit PUBLIQUES, trouvés par ce même inventaire, ont été retirés pour tous par le lot
COST-1 (`docs/boutique.md`, « Le coût ne sort pas des routes ouvertes »).

## 6. Suite

- **AG-3b** : entrée dans le site d'un client par un jeton tracé des deux côtés, garde du mode agence fermée par défaut d'après ce
  registre, retrait des champs sensibles, essai sur toutes les routes.
- **AG-3c** : menu d'EasyAdmin piloté par le registre (mode et modules), garde sur toutes les sections.
- **AG-3d** : « Tous mes clients ».

## Tenir cette fiche à jour

À chaque écran, famille ou liste hors écran ajoutés ; à chaque champ sensible trouvé ; à chaque mode nouveau.
