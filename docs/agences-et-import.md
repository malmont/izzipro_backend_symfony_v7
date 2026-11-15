# Agences et import d'une boutique existante : plan

**État : PLAN, aucun code.** Écrit le 15/11/2026 à la demande du chef de projet, d'après les décisions du propriétaire du même jour.
Le propriétaire a répondu le jour même aux onze questions du plan : ses réponses sont au § 6. Ordre retenu : IMP-1 d'abord, puis AG-1.
Rien de ce qui suit n'existe dans le code tant qu'un lot n'est pas marqué « codé ». Chaque lot aura son contrat, validé avant le code.
Le lot AGENCE est l'« espace partenaire » annoncé par `docs/incidents-et-signalements.md` et `docs/gestion-mise-a-niveau.md` : un seul
chantier, pas deux.

## 1. Les décisions du propriétaire (15/11/2026)

**Agences**

1. L'agence paie à la plateforme un prix de gros et refacture ses propres clients. Elle paie en plus les options : module Assistance,
   prévention des pertes, unités IA, fonctionnalités ajoutées. Un forfait négocié avec une agence doit être possible.
2. L'agence crée elle-même les sites de ses clients. La plateforme garde la main sur les offres, les forfaits et la suspension.
3. Marque blanche, niveau a : le logo et le nom de l'agence dans l'espace d'administration et dans les courriels. Le domaine propre de
   l'agence viendra plus tard.
4. Offre de départ : 60 jours gratuits, jusqu'à 3 sites de clients, tous les modules.

**Import**

5. Shopify d'abord, puis WooCommerce, puis PrestaShop.
6. D'abord les articles, les variantes, les images et les catégories, par le fichier d'export (CSV), sans connexion directe. Ensuite les
   clients, avec leur consentement (Loi 25) et l'appartenance à la boutique de source « import ». L'historique des commandes en dernier.

## 2. Ce qui existe déjà et sur quoi le plan s'appuie

| Sujet | Aujourd'hui | Référence |
|---|---|---|
| Création d'un site | Formulaire `/setup/new-store`, protégé par une clé secrète de la plateforme ; copie de la base modèle `gmasuite`. Pendant la copie, la base modèle est fermée aux connexions | `TenantSetupController`, `TenantConnectionManager::createTenant`, `docs/architecture.md` |
| Droits d'un site | Table maître `tenant_entitlements` : un droit par fonctionnalité, avec une source (`manual`, `trial`, `free`, `subscription`, `purchase`), des dates et un état (actif, grâce, suspendu…) | `docs/console-pilotage.md` § 2 |
| Offres et prix publics | Table maître `platform_offers` (prix par mois en cents, essai, fonctionnalités de l'offre) | idem |
| Mode de la plateforme | `demo` (rien n'est bloqué) ou `live` | idem |
| Comptes | Un compte vit dans la base d'UN site. Le jeton de connexion porte le code de ce site et est refusé ailleurs | `JWTCreatedListener`, `JWTDecodedListener` |
| Super administrateur | `ROLE_SUPER_ADMIN`, testé explicitement, seul à voir `/api/platform/*` et l'écran Plateforme | `config/packages/security.yaml` |
| Nom et logo montrés | Fiche Entreprise du site, profils d'application (`app_profile`), nom du site dans les courriels (`Services/Site/SiteName`) | `docs/architecture.md` |
| Appartenance d'un client à la boutique | `user_app`, source `register`, `join`, `guest_checkout`, `pos_link`, `backfill` ; jamais d'ajout silencieux | `docs/apps-membres.md` |
| Code-barres unique | Lot CB-1 (garanti par la base, forme canonique) | `docs/codes-barres.md` |
| Catalogue | Article (`Product` : nom, description, prix en cents, coût d'achat, catégorie, images, visible sur le web et en caisse) et variante (`ProductVariant` : couleur, taille, valeurs d'options, stock, prix, code-barres) | `docs/boutique.md` |

## 3. AGENCE

### 3.1 Le choix de fond : l'agence est d'abord un site

Deux façons de donner une identité à une agence :

- **A. Un compte d'agence dans la base maître**, avec sa propre connexion, au-dessus des sites. Il faut alors refaire, pour ce compte,
  tout ce que les comptes de site ont déjà : connexion, code à usage unique, mot de passe oublié, limites de débit, sessions du web.
- **B. L'agence a son propre site (son « site d'attache »)**, et ses collaborateurs sont des comptes de ce site qui portent un rôle
  d'agence. La base maître dit seulement « ce site est le site d'attache de telle agence, et telle agence gère tels sites ».

**Décidé par le propriétaire (15/11/2026) : B.** Tout ce qui protège une connexion existe déjà et reste unique. L'agence a de toute façon besoin d'un site (sa
vitrine). Le code neuf se limite au lien agence ↔ sites, au passage vers le site d'un client et à la facturation.

### 3.2 Modèle de données (base maître)

| Table | Contenu |
|---|---|
| `agencies` | code, nom, site d'attache (unique), état (`trial`, `active`, `suspended`, `closed`), fin de l'essai, nombre maximum de sites, forfait, devise, courriel de contact ; marque : nom affiché, logo, nom d'expéditeur des courriels, adresse d'assistance |
| `agency_sites` | agence, code du site (un site n'a qu'une agence), origine (`created` par l'agence, `attached` par la plateforme), dates de rattachement et de détachement, auteur |
| `agency_plans` | code, libellé, devise, jours d'essai, nombre maximum de sites, prix de gros par site et par mois selon des paliers de nombre de sites, fonctionnalités comprises, prix de chaque option par site et par mois, prix des unités IA. Une ligne sans agence = la grille commune ; une ligne portant une agence = son forfait négocié |
| `agency_statements`, `agency_statement_lines` | le relevé mensuel d'une agence : une ligne par site et par option, figée à la clôture du mois ; état (`open`, `closed`, `paid`) |
| `agency_events` | journal : site créé, option ouverte ou fermée, entrée dans un site, changement de forfait, suspension, reprise |

Les droits d'un site géré restent dans `tenant_entitlements`, avec une source nouvelle : `agency` (référence « agence n° … »). Rien
d'autre ne change dans la lecture des droits : un site d'agence se comporte comme un site abonné. Le niveau automatique des courriels
(`NotificationLevelResolver`) compte la source `agency` parmi les droits payés.

### 3.3 Rôles et limites

| Rôle | Où | Peut | Ne peut pas |
|---|---|---|---|
| `ROLE_AGENCY` (responsable de l'agence) | compte du site d'attache | voir ses sites, en créer dans la limite du forfait, ouvrir ou fermer les modules et options que son forfait prévoit, régler la marque, gérer ses collaborateurs, lire ses relevés, entrer dans un de ses sites | changer un prix, un forfait, une limite ; lever une suspension ; voir un site qui n'est pas le sien |
| `ROLE_AGENCY_MEMBER` (collaborateur) | compte du site d'attache | voir les sites et y entrer | créer un site, toucher aux options, à la marque, aux relevés |
| Opérateur d'agence | compte du site du client, créé par le passage (§ 3.5) | ce que peut un administrateur du site | rien de la plateforme ; jamais `ROLE_SUPER_ADMIN` |
| Super administrateur | partout | créer une agence, fixer son forfait et ses prix, rattacher ou détacher un site, suspendre une agence ou un site | |

Règle de sécurité : l'agence d'une requête se lit toujours du site d'attache sur lequel le compte est connecté, jamais d'un paramètre.
Une route `/api/agency/*` appelée ailleurs que sur un site d'attache répond 403.

### 3.4 Créer le site d'un client

`POST /api/agency/sites` (code, nom, pays, modules voulus, courriel du commerçant facultatif). Le serveur vérifie l'état de l'agence, la
limite de sites et le code demandé, puis met la création en file : la copie de la base modèle est lourde et ferme un instant le modèle,
elle ne se fait donc pas dans la requête mais par un worker, une à la fois. États : `pending`, `creating`, `ready`, `failed`. À la fin :
ligne `agency_sites`, droits de source `agency`, invitation du commerçant s'il y en a un. Limite de débit par agence. La clé secrète de
`/setup/new-store` reste la voie de la plateforme, inchangée.

### 3.5 Entrer dans le site d'un client

Le jeton d'un compte ne vaut que pour son site. Pour passer du site d'attache au site d'un client : `POST /api/agency/sites/{code}/enter`
rend un code de passage à usage unique, valable une minute ; le site du client l'échange contre une session d'administrateur ordinaire,
ouverte au nom d'un compte « opérateur d'agence » propre à ce collaborateur (sans mot de passe : on n'y entre que par le passage).
Chaque entrée est écrite au journal de l'agence et au journal du site. Le commerçant voit ce compte dans son écran Utilisateurs, avec la
mention de l'agence, sans pouvoir le couper : seule la plateforme détache un site. Un opérateur ne reçoit aucun courriel du site et ne
compte pas parmi ses clients. **Avant l'ouverture des agences au public, la clause des conditions qui annonce cet accès (Loi 25) doit
être relue par un juriste** (décision du propriétaire, 15/11/2026).

### 3.6 Marque blanche, niveau a

Une seule source : la marque de l'agence (`agencies`), lue par un service unique pour tout site géré. Elle remplace le nom et le logo de
la plateforme dans l'en-tête de l'espace d'administration (EasyAdmin et web) et dans les courriels du serveur (nom d'expéditeur,
signature, adresse d'assistance). L'adresse technique d'envoi reste celle de la plateforme, pour que les courriels arrivent. Le nom du
commerce, lui, reste celui de la fiche du site. Aucune mention « propulsé par » ; une option pourra venir plus tard.

### 3.7 Prix, relevé, essai, suspension

- Le relevé du mois se calcule : pour chaque site géré, le prix de gros du palier atteint, plus chaque option ouverte, plus les unités IA
  consommées au-delà de ce que le forfait comprend. Un mois entamé se compte au prorata des jours.
- Offre de départ : à la création, l'agence est en essai (60 jours, 3 sites de clients, tous les modules). Rappels 7 jours et 1 jour
  avant la fin. Le site de l'agence elle-même est gratuit pendant l'essai et ne compte pas dans les 3 ; ensuite il est payant au prix
  d'agence et suit les règles d'un site géré : forfait d'agence, options payantes en plus, accès à ce qui est payé.
- Sans forfait payé à la fin : 7 jours de grâce, puis suspension de l'agence, qui suspend les droits de source `agency` de ses sites.
  La page publique d'un commerçant reste en ligne 30 jours pendant la suspension. Le super administrateur peut aussi suspendre un seul site.
- Le paiement : au départ, un relevé mensuel réglé hors de la plateforme (virement ou facture) et marqué « payé » par le super
  administrateur ; le prélèvement par carte viendra dans un lot suivant.
- Les prix (paliers du prix de gros, options, unités IA) ne sont pas encore fixés : AG-1 n'en a pas besoin, ils servent à AG-5.

### 3.8 Écrans

| Qui | Où | Quoi |
|---|---|---|
| Super administrateur | EasyAdmin, écran Plateforme, onglet « Agences » ; `/api/platform/agencies` | liste, création, forfait et prix négociés, sites rattachés, suspension, relevés, journal |
| Agence | web (l'application de gestion clonée), « Espace agence » ; `/api/agency/*` | tableau de ses sites (état, modules, fin d'essai, alertes), créer un site, options d'un site, marque, collaborateurs, relevés, « Ouvrir ce site » |
| Commerçant | EasyAdmin et web de son site | bandeau « Géré par … », compte de l'opérateur visible dans Utilisateurs |

### 3.9 Lots

| Lot | Contenu | Risque |
|---|---|---|
| AG-1 | Tables maîtres, agence et forfait créés par le super administrateur, rattachement de sites existants, rôles sur le site d'attache, lecture `GET /api/agency/me` et `/sites` (**codé le 15/11/2026** : § 7) | comptes et droits |
| AG-2 | Création d'un site par l'agence (file, limites), droits de source `agency`, options ouvertes et fermées dans le cadre du forfait, suspension en cascade | droits, base |
| AG-3 | Entrer dans le site d'un client (code de passage, opérateur, journaux, mention au commerçant) | sécurité |
| AG-4 | Marque blanche, niveau a | faible |
| AG-5 | Relevé mensuel, fin d'essai, rappels, suspension automatique | argent |
| AG-6 | Vue de l'agence sur les incidents, les signalements et l'assistance de ses sites | faible, plus tard |

Tous les lots sauf AG-4 et AG-6 sont des lots à risque : relecture indépendante, mutations, photo avant et après.

## 4. IMPORT

### 4.1 Principe : un import se lit, se confirme, puis s'exécute

1. **Dépôt** du fichier d'export par un administrateur du site (ou par l'agence entrée dans le site). Stockage privé ; taille et nombre
   de lignes plafonnés.
2. **Analyse, sans rien écrire** : nombre d'articles, de variantes, de catégories à créer, d'images ; lignes refusées, chacune avec sa
   raison ; codes-barres en double ou déjà pris (règle de CB-1) ; prix lus dans la devise du site.
3. **Confirmation** par l'administrateur, sur ce rapport.
4. **Exécution** par un worker, article par article. Rejouer le même fichier ne crée pas de doublon : chaque article et chaque variante
   importés gardent leur référence d'origine (source + identifiant du fichier).
5. **Rapport final**, et **annulation** possible pendant un délai : les articles créés par l'import et jamais vendus sont retirés.

Une table `import_job` par site (source, nature, état, compteurs, auteur, empreinte du fichier) et une référence d'import sur les
articles et variantes créés. Un lecteur par source (Shopify, puis WooCommerce, puis PrestaShop) derrière la même interface : le reste
du circuit ne change pas.

### 4.2 Catalogue Shopify (fichier d'export des produits)

| Colonne Shopify | Devient |
|---|---|
| Handle (commun aux lignes d'un même produit) | référence d'import ; adresse de la fiche |
| Title, Body (HTML), Tags | nom, description (HTML nettoyé), mots-clés |
| Type ou Product Category | catégorie, créée si elle manque |
| Option1 à Option3 (nom et valeur) | variantes : couleur, taille, ou valeurs d'options |
| Variant Price, Variant Compare At Price | prix en cents ; prix barré si présent |
| Cost per item | coût d'achat (application de gestion) |
| Variant Inventory Qty | stock de départ |
| Variant Barcode | code-barres, par la règle de CB-1 ; un code refusé ou déjà pris est signalé, l'article est importé sans code |
| Variant SKU | référence de la variante |
| Image Src, Image Position, Variant Image | images, téléchargées par le worker depuis les seuls serveurs d'images de Shopify, taille et type contrôlés |
| Status, Published | visible ou non sur le web |

### 4.3 Clients, avec leur consentement

- Le fichier des clients crée des **comptes invités** : pas de mot de passe (Shopify ne les exporte pas), pas de connexion possible, pas
  d'appartenance à la boutique.
- Le commerçant décide d'envoyer l'invitation. Le client qui l'accepte choisit son mot de passe et accepte les conditions : c'est à ce
  moment seulement que naît l'appartenance `boutique`, de source `import`, avec la version acceptée et la date.
- Le consentement aux courriels commerciaux n'est repris que s'il est marqué dans le fichier, et le commerçant atteste qu'il l'a obtenu ;
  l'attestation est au journal du site.
- Un client invité qui n'a pas accepté au bout de 90 jours est effacé.

### 4.4 Historique des commandes

En dernier. Les commandes importées vont dans une archive à part : visibles dans la fiche du client et dans une série « ventes
d'avant » des statistiques, jamais dans le journal de caisse, la numérotation des factures, les taxes ni les exports comptables.

### 4.5 Lots

| Lot | Contenu | Risque |
|---|---|---|
| IMP-1 | Catalogue Shopify : dépôt, analyse, confirmation, exécution, images, rapport, annulation | données |
| IMP-2 | Clients avec consentement (**codé le 15/11/2026** : `docs/import-boutique.md` § 9) | données personnelles |
| IMP-3 | Historique des commandes | données |
| IMP-4, IMP-5 | Lecteurs WooCommerce, puis PrestaShop | faible |

IMP-1 ne dépend pas d'AGENCE. **Ordre décidé : IMP-1 d'abord, puis AG-1.** Chaque lot a son contrat, validé par le chef avant le code.

## 5. Risques

| Risque | Parade prévue |
|---|---|
| Une agence voit ou modifie le site d'une autre | l'agence se lit du site d'attache, jamais d'un paramètre ; tests de cloisonnement à chaque route ; relecture indépendante |
| Le code de passage volé ou rejoué | usage unique, une minute, lié au collaborateur et au site, journal des deux côtés |
| La création de sites en rafale bloque la base modèle | file avec un worker, une création à la fois, limite par agence |
| Un relevé faux | lignes figées à la clôture du mois, calcul rejouable, tests sur les prorata et les paliers |
| Une suspension coupe la page publique d'un commerçant | la page publique reste en ligne 30 jours pendant la suspension (§ 3.7) |
| Données personnelles importées sans base légale | comptes invités, rien d'actif sans acceptation, attestation du commerçant, effacement au délai |
| Fichier d'import piégé (HTML, adresses d'images) | HTML nettoyé, images prises sur une liste de serveurs permis, plafonds de taille |
| Doublons de codes-barres | règle de CB-1, déjà garantie par la base |

## 6. Réponses du propriétaire aux questions du plan (15/11/2026, relayées par le chef de projet)

| # | Question | Décision |
|---|---|---|
| 1 | L'agence est-elle d'abord un site (§ 3.1, option B) ? | Oui |
| 2 | Le site de l'agence est-il gratuit, compte-t-il parmi les 3 ? | Gratuit pendant l'essai de 60 jours seulement, hors des 3 sites ; ensuite payant au prix d'agence. Une fois payant, il suit les mêmes règles qu'un site de client : le forfait d'agence, plus les options payantes (Assistance, prévention des pertes, unités IA, fonctionnalités ajoutées) ; l'agence a accès à ce qu'elle paie. Aucun garde-fou particulier |
| 3 | Qui ouvre une agence ? | Le super administrateur, sur demande |
| 4 | Paliers du prix de gros, prix des options et des unités IA | **Ouvert** : les prix suivront. AG-1 n'en a pas besoin ; ils serviront à AG-5 |
| 5 | Paiement | Relevé mensuel réglé hors de la plateforme et marqué « payé » par le super administrateur ; la carte dans un lot suivant |
| 6 | Fin des 60 jours sans forfait payé | 7 jours de grâce ; page publique du commerçant gardée 30 jours |
| 7 | Mention « propulsé par » | Aucune. Une option pourra venir plus tard |
| 8 | L'agence administrateur du site de son client, que le commerçant ne peut pas couper | Oui. La clause de la Loi 25 sera relue par un juriste avant l'ouverture des agences au public |
| 9 | Stock et commandes importés | Les quantités du fichier deviennent le stock de départ ; les commandes importées vont dans une série à part « ventes d'avant », jamais dans la caisse, les factures, les taxes ni les exports comptables |
| 10 | Durée de garde d'un client invité qui n'a pas accepté | 90 jours, puis effacement |
| 11 | Ordre | IMP-1 d'abord, puis AG-1 |

Reste à faire avant le code : le contrat d'IMP-1 (envoyé au chef en dix lignes), après CB-1, CB-2 et NOTIF-2.

## 7. Lot AG-1 : ce qui est codé (15/11/2026)

Code : `src/Services/Agency/` (`AgencyRepository` : base maître en PDO ; `AgencyService` : créer, rattacher, détacher, suspendre,
reprendre ; `AgencyMemberService` : rôles sur le site d'attache ; `AgencyContext` : l'agence de la requête ; `AgencyException` : refus
à code stable), `src/Security/AgencyRoles.php`, `src/Security/AgencyScopeSubscriber.php`, contrôleurs
`PlatformController/PlatformAgenciesController`, `AgencyController/AgencyController`, `Admin/PlatformAgenciesAdminController` (page
« Plateforme : agences »). Test : `tests/Functional/Platform/AgenciesTest.php`.

**Aucune agence n'existe en production** : pas d'agence de test avant AG-2 ou la campagne de tests (décision du propriétaire). Le lot
ne touche à aucune base de site ni à aucun droit d'usage : rattacher un site ne change rien pour lui.

### 7.1 Base maître

`ops/sql/agency_master.sql`, appliqué par `scripts/migrate_master_v2_agencies.sh` (hors dépôt) et relu par `tests/bootstrap.php` :
`agencies` (code unique, **site d'attache unique**, état `trial` | `active` | `suspended` | `closed`, fin de l'essai, nombre maximum de
sites, forfait, courriel de contact, marque « agence de test »), `agency_sites` (un **index unique partiel** garantit qu'un site n'a
qu'une agence à la fois), `agency_plans` (une ligne commune `essai` : 60 jours, 3 sites ; les prix arrivent au lot AG-5),
`agency_events` (journal en ajout seul). Dates en UTC, sans fuseau. Pas de migration Doctrine : c'est la base maître.

### 7.2 Règles

- Création : en essai, 60 jours, 3 sites gérés au plus (forfait commun `essai`) ; **le site d'attache n'est pas compté**.
- Un site n'a qu'une agence ; un site d'attache n'en sert qu'une et n'est jamais un site géré.
- Ne se confient jamais, ni comme site d'attache ni comme site géré (`AgencyService::RESERVED_SITES`) : `gmasuite`, `tenantdefaut`,
  `demo` (le site de contrôle des mises en ligne).
- Deux codes de site qui partagent une base (les mêmes comptes) ne se confient pas deux fois : `AGENCY_SITE_SHARES_DATABASE`.
- Une agence suspendue ou fermée ne reçoit plus de site ; une agence suspendue lit encore son espace, avec son état.
- **La fin de l'essai est affichée (`trialEndsAt`, `trialEnded`) : rien ne se ferme tout seul dans ce lot.** Lever une suspension
  rend l'agence à l'essai si sa fin n'est pas passée, sinon `active`.

### 7.3 Rôles d'agence

`ROLE_AGENCY` (responsable) et `ROLE_AGENCY_MEMBER` (collaborateur), sur des comptes du **site d'attache**. Un compte n'en porte
qu'un ; ses autres rôles ne bougent pas.

- **Donnés et retirés par le super administrateur seulement** (`PUT /api/platform/agencies/{id}/members`, page « Plateforme :
  agences »), par une connexion directe à la base du site d'attache (`TenantDatabaseConnector`), sans changer le site de la requête.
  Journal : `agency_events` (`role_granted`, `role_revoked`, avec l'identifiant du compte, jamais son adresse).
- Ils ne sont ni dans `TeamRoles::ALL` ni dans `RoleAssignmentPolicy::ASSIGNABLE` : l'écran des utilisateurs ne les propose pas, un
  formulaire forgé ne les ajoute pas, et un rôle d'agence déjà présent est conservé (un administrateur de site ne peut pas le retirer).
- Un compte d'agence est un **compte d'équipe** (`AppFamilies::STAFF_ROLES`), pas un client.
- **Un compte d'agence seul n'atteint que `/api/agency`** (`AgencyScopeSubscriber`, même mécanique que la garde de l'assistance) :
  toute autre route protégée répond 403 (`AGENCY_SCOPE_ONLY` quand la route ne demande qu'un compte connecté, `FORBIDDEN` quand c'est
  la règle d'accès qui refuse) ; une route publique lui est servie comme à un anonyme. S'il porte aussi `ROLE_SUPPORT`, `/api/support`
  lui reste ouvert. Un compte qui porte un autre rôle d'accès (administrateur du site d'attache, par exemple) garde ses accès.
- Connexion : la plateforme `webadmin` (l'espace d'administration du web) accepte un compte d'agence ; `web`, `mobile`, `admin` et
  `pos` le refusent s'il n'a pas le rôle qu'elles exigent.

### 7.4 Routes de l'agence (`ROLE_AGENCY` ou `ROLE_AGENCY_MEMBER`, règle `^/api/agency` placée avant la règle générale `^/api`)

**Règle de sécurité : l'agence est lue du site de la requête (`AgencyContext`), jamais d'un paramètre, d'un en-tête ou du corps.** Le
jeton d'un site ne vaut pas sur un autre (401). Sur un site qui n'est pas le site d'attache d'une agence, ou sans site reconnu, même
un compte qui porterait le rôle reçoit 403 `AGENCY_SCOPE`.

| Route | Réponse |
|---|---|
| `GET /api/agency/me` | `{ agency, role }` ; `role` : `manager` \| `member` |
| `GET /api/agency/sites` | `{ sites: [{ code, name, host, origin, attachedAt }], maxSites, used }` |

`agency` : `{ id, code, name, status, isTest, homeSite: { code, name, host }, trialEndsAt, trialEnded, maxSites, sitesCount, planCode,
contactEmail, suspendedAt, createdAt }` (dates ISO 8601, UTC).

### 7.5 Routes de la plateforme (`ROLE_SUPER_ADMIN`, `/api/platform/agencies`)

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `GET /api/platform/agencies` | — | `{ agencies }` | |
| `POST /api/platform/agencies` | `{ code, name, homeTenantCode, contactEmail?, test? }` | 201 `{ agency }` | 422 `VALIDATION_FAILED { errors: [{ path, message }] }` ; 409 `AGENCY_SITE_RESERVED`, `AGENCY_CODE_TAKEN`, `AGENCY_HOME_TAKEN`, `AGENCY_SITE_SHARES_DATABASE` |
| `GET /api/platform/agencies/{id}` | — | `{ agency, sites, members, events }` | 404 `AGENCY_NOT_FOUND` |
| `POST /api/platform/agencies/{id}/sites` | `{ tenantCode }` | 201 `{ agency, sites }` | 422 ; 409 `AGENCY_NOT_OPEN`, `AGENCY_SITE_RESERVED`, `AGENCY_SITE_TAKEN`, `AGENCY_SITE_SHARES_DATABASE`, `AGENCY_SITE_LIMIT { maxSites }` |
| `DELETE /api/platform/agencies/{id}/sites/{tenantCode}` | — | `{ agency, sites }` | 404 `AGENCY_SITE_NOT_ATTACHED` |
| `POST /api/platform/agencies/{id}/suspend` | `{ reason? }` | `{ agency }` | 409 `AGENCY_STATE` |
| `POST /api/platform/agencies/{id}/resume` | — | `{ agency }` | 409 `AGENCY_STATE` |
| `PUT /api/platform/agencies/{id}/members` | `{ email, role: "manager" \| "member" \| "none" }` | `{ member: { userId, email, role, changed }, members }` | 422 ; 404 `AGENCY_MEMBER_NOT_FOUND` ; 409 `AGENCY_NOT_OPEN` (agence fermée) |

La page d'administration `/admin/plateforme/agences` (menu « Plateforme : agences », super administrateur) fait les mêmes gestes par
les mêmes services ; chaque action refait le contrôle du rôle et du jeton CSRF.

### 7.6 Limites connues

- Les rôles d'agence ne sont pas retirés quand un site cesse d'être un site d'attache (aucune route ne ferme une agence dans ce lot) ;
  sans agence sur ce site, ils n'ouvrent rien (403 `AGENCY_SCOPE`).
- Aucun droit de source `agency`, aucune création de site, aucune entrée dans un site géré, aucun relevé : lots AG-2 à AG-5.
- La page d'administration liste tous les sites comme candidats, sauf les sites réservés ; les refus sont dits au moment du geste.

## Tenir cette fiche à jour

À chaque décision du propriétaire, la reporter au § 1 ou au § 6. À chaque lot codé, le dire en tête et dans le
tableau des lots, avec le contrat du lot.
