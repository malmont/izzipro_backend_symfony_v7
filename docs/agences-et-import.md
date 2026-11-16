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

**Remplacé par le lot AG-3b (16/11/2026, `docs/ecrans-et-contextes.md` § 7)** : l'agence entre par un jeton d'entrée de 15 minutes,
sans aucun compte chez le client, comme opérateur extérieur borné à la construction ; chaque entrée est tracée dans la base maître et
dans « Accès extérieurs » du site. Le texte ci-dessous est le plan d'origine. **Migration faite par une agence** : les clients et les
commandes ne s'importent pas en entrée ; le commerçant les importe lui-même, ou le super administrateur (le catalogue, lui, s'importe
en entrée).

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
| AG-2 | Création d'un site par l'agence (file, limites), droits de source `agency`, options ouvertes et fermées dans le cadre du forfait, suspension en cascade (**codé le 15/11/2026, § 8**) | droits, base |
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
  `demo` (le site de contrôle des mises en ligne), ni un second code de site posé sur la base de l'un d'eux.
- « Un site d'attache n'est jamais un site géré » ne tient à aucune contrainte de la base : la création d'une agence et le
  rattachement d'un site prennent le même verrou du site (`pg_advisory_xact_lock`) et revérifient sous ce verrou.
- Le nom d'une agence ne porte ni `<` ni `>` (les messages de la page d'administration sont de toute façon échappés).
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
  `pos` le refusent s'il n'a pas le rôle qu'elles exigent. Le lien « mot de passe oublié » demandé depuis cet espace y ramène.
- **Retirer le rôle d'agence d'un compte qui n'a plus aucun autre rôle d'accès clôt ses sessions** (jetons de renouvellement
  retirés, comme pour un agent d'assistance révoqué) : sans cela sa session du web se renouvellerait et, n'étant plus « agence seul »,
  il atteindrait les routes qui ne demandent qu'un compte connecté. Le jeton d'accès restant vit 15 minutes au plus.
- La route de paiement `POST /api/stripe/create-intent` lit le jeton elle-même, hors du pare-feu (`OptionalCustomerResolver`) : un
  compte borné à un seul espace (agence seul, assistance seul) n'y est jamais reconnu comme client.
- Un agent d'assistance qui reçoit un rôle d'agence reste un agent que l'administrateur du site peut révoquer ; la révocation lui
  laisse son rôle d'agence, qui ne se retire que par la plateforme.
- Pour le front : un compte d'agence seul reçoit 403 sur `/api/profile`, `/api/validate-token` et `/api/account/memberships` ;
  l'espace de l'agence s'appuie sur `GET /api/agency/me`.

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
- Aucune entrée dans un site géré, aucun relevé : lots AG-3 à AG-5 (la création de site et les droits `agency` sont au § 8, lot AG-2).
- La page d'administration liste tous les sites comme candidats, sauf les sites réservés ; les refus sont dits au moment du geste.
- Un compte client (`ROLE_USER_INTERNET`) qui reçoit un rôle d'agence devient un compte d'équipe : en mode « exiger », il n'a plus à
  rejoindre une famille d'applications (même règle que les autres rôles d'équipe).
- Un rôle d'agence retiré par un autre chemin (effacement du compte) n'est pas écrit au journal de l'agence.
- Aucune clé étrangère vers `tenants` : un code de site supprimé puis recréé retrouverait son agence.

## 8. Lot AG-2 : ce qui est codé (15/11/2026)

Création d'un site par l'agence, par une file ; droits d'usage de source `agency` ; suspension en cascade ; et, pour **toute** création
de site, ce qu'un site neuf n'hérite plus du site de référence. Code : `src/Services/Agency/` (`AgencySiteRequestService`,
`AgencySiteRequestRepository`, `AgencySiteBuilder`, `AgencySiteInviter`, `AgencyEntitlements`, `AgencySiteCode`, messages et
gestionnaire du worker), `src/Services/TenantConnectionManager.php` (création), `src/Services/Platform/PlatformAccessJournal.php`.
Tests : `tests/Functional/Platform/AgencySitesTest.php`, `tests/Functional/Platform/PlatformAccountGuardsTest.php`.

Réponses du propriétaire (15/11/2026) : le courriel de l'administrateur est obligatoire ; les sites rattachés à la main restent
inchangés ; pas de création après la fin de l'essai ; les unités d'IA suivent la plateforme en attendant AG-5 ; le plafond de demandes
par jour est un garde-fou technique réglable par agence ; le nombre de sites vient du forfait et peut valoir « illimité » ; l'adresse
est choisie librement par l'agence, dans les règles de forme.

### 8.1 Le geste et la file

Le **responsable** de l'agence (`ROLE_AGENCY` ; le collaborateur lit seulement) demande un site : code (son adresse), nom, courriel de
la personne qui l'administrera. La réponse est immédiate, `pending` ; le site est fabriqué par le worker `media`
(`BuildAgencySiteMessage`, jamais relancé), **un seul à la fois pour toutes les agences** (verrou consultatif de la base maître : la
copie ferme un instant le site de référence ; le formulaire `/setup/new-store` et `app:tenant:create` ne prennent pas ce verrou : une
copie qui tomberait en même temps ferait échouer l'une des deux, sans rien détruire). États : `pending` → `building` → `ready` | `failed` (`errorCode`).

Refus avant la file : agence suspendue ou fermée (`AGENCY_NOT_OPEN`) ; essai terminé (`AGENCY_TRIAL_ENDED`) ; limite de sites atteinte,
demandes en cours comprises (`AGENCY_SITE_LIMIT`) ; plus de `daily_site_requests` demandes en 24 heures, quelle que soit leur issue
(429 `AGENCY_REQUEST_LIMIT`) ; code mal formé (422), réservé (`AGENCY_SITE_CODE_RESERVED`) ou pris (`AGENCY_SITE_CODE_TAKEN`).

**Code du site** (`AgencySiteCode`) : 3 à 40 caractères, lettres minuscules, chiffres et tirets, une lettre d'abord, ni tiret final ni
deux tirets de suite, ni « -v2 » final (la résolution d'un hôte retire ce suffixe : l'adresse mènerait à un autre site). Sa base s'appelle `db_<code>`, tirets devenus soulignés. Un code est « pris » s'il désigne un site (tiret et
souligné tenus pour équivalents), une base qui existe sur le serveur, un domaine propre, une demande en cours, ou s'il reste à son nom
dans la base maître un droit d'usage vivant ou une clé d'application (un site neuf n'hérite pas d'un ancien site du même nom).

Base maître : table `agency_site_requests` (un index unique partiel garantit qu'un code n'est demandé qu'une fois à la fois),
`agencies.daily_site_requests` (5 par défaut), `agencies.max_sites` et `agency_plans.max_sites` qui acceptent le vide (« illimité »).
Script hors dépôt : `scripts/migrate_master_v2_agencies_ag2.sh`.

### 8.2 La fabrication

`AgencySiteBuilder`, avec les accès de la plateforme, jamais ceux de l'agence :

1. la même copie du site de référence que pour tout site neuf (`TenantConnectionManager::createTenant`) ;
2. la fiche du site : son nom, le courriel de son administrateur comme contact, son adresse publique ; langue, fuseau, devise, pays et
   province si la demande les donne ;
3. les droits de source `agency` (§ 8.4) ;
4. les référentiels automatiques de tout site neuf (taxes d'après le pays, expéditeur des courriels, profils des applications, essai
   « Aide IA » de la plateforme), puis la projection des droits sur la fiche (même sans module demandé, la fiche ne garde aucun module
   ouvert par défaut) ;
5. le rattachement à l'agence (origine `created`) et le passage à `ready`, dans une même transaction ;
6. l'invitation de l'administrateur désigné (§ 8.3).

**Jamais un site à moitié fait** : si la copie échoue, rien n'existe ; si la fiche ou le rattachement final échouent, le site que cette
fabrication vient de créer est défait (ligne retirée, base supprimée, droits annulés : `discardCreatedTenant`, qui ne touche qu'à un
site créé par le processus en cours). Codes d'échec : `BUILD_FAILED`, `SITE_CODE_TAKEN`, `AGENCY_NOT_OPEN`, `ATTACH_FAILED`,
`INTERRUPTED`. Une demande en échec rend sa place et son code ; elle n'est jamais refabriquée : l'agence en fait une autre.

**Rattrapage** : à chaque passage horaire (`app:gestion:notifications:envoyer`), une fabrication « en création » depuis plus de
15 minutes passe en échec `INTERRUPTED`, et une demande restée en attente plus de 15 minutes est confiée de nouveau au worker. Un worker
arrêté pendant la copie laisserait le site de référence fermé : le rattrapage le rouvre. Arrêté après la copie, il laisse un site sans
agence : ses droits `agency` sont annulés, l'événement `site_failed` porte `siteExists`, et la plateforme regarde ce site (rien n'est
supprimé tout seul). `app:agency:sites:build` fabrique sur place les demandes
en attente (rattrapage à la main, répétition).

### 8.3 L'administrateur du site

Le courriel de l'administrateur est **obligatoire**. La personne reçoit un courriel (`site_admin_invitation`, français ou anglais,
classé « indispensable », texte validé par le propriétaire le 16/11/2026) avec un lien à usage unique, valable **7 jours**, vers
l'écran du web où elle choisit son mot de passe (`/admin/activer#token=…`, puis `POST /api/password-reset/verify` et `/confirm` sur le
site neuf). Le courriel part par l'**expéditeur de la plateforme**, réponses vers le contact de l'agence : l'expéditeur d'un site neuf
est l'adresse de son administrateur, et un courriel « de » cette adresse « à » cette adresse serait rejeté. Son compte (`ROLE_ADMIN`,
canal `admin_invite`) ne sert à rien avant : mot de passe aléatoire, compte non vérifié. Le jeton n'est jamais rendu à l'agence.

Le responsable peut faire **renvoyer** le lien (l'ancien ne vaut plus), une fois par site toutes les 10 minutes
(429 `AGENCY_INVITATION_TOO_SOON`) ; si la première invitation n'avait pas abouti, le compte est créé à ce moment. Il peut aussi
**désigner une autre personne** (`adminEmail` dans le corps) tant que la première n'a pas choisi son mot de passe : le compte jamais
activé de la première est retiré ; ensuite, changer d'administrateur est l'affaire du site (409 `AGENCY_ADMIN_ACTIVE`). Le renvoi est
fait par le worker et son issue se lit sur la demande (`invitation { sent, reason, expiresAt }` ; `reason` : `ACCOUNT_EXISTS`,
`ALREADY_ACTIVE`, `NO_LINK`, `EMAIL_FAILED`, `INVITATION_FAILED`). Un compte qui existe déjà à cette adresse sans être né de
l'invitation n'est jamais transformé ni invité : `ACCOUNT_EXISTS`, et l'agence désigne une autre adresse.

### 8.4 Droits de source `agency`

- Seuls les sites **créés** par l'agence en portent ; un site rattaché à la main par la plateforme reste inchangé
  (`AGENCY_SITE_NOT_CREATED`).
- À la création : les modules et les applications (`module.*`, `app.*`, 10 clés), ou la liste `modules` de la demande. **Pas l'IA** :
  elle suit la plateforme (essai « Aide IA » automatique de tout site neuf), en attendant AG-5. Sans fin, sans courriel au site.
- L'agence ferme ou rouvre un module d'un de ses sites. Elle ne touche jamais à un droit qu'elle n'a pas posé : un droit vivant d'une
  autre source (essai, abonnement, accord de la plateforme) n'est ni fermé ni remplacé, et un droit **suspendu** (par la plateforme, ou
  un abonnement impayé) ne se contourne pas en en posant un neuf (`AGENCY_MODULE_HELD`).
- **Suspension en cascade** : suspendre l'agence suspend ses droits sur les sites qu'elle a créés, sauf la page publique
  (`module.landing`), gardée **30 jours** en « grâce », puis suspendue par le balayage des droits. Aucun courriel au site au moment de
  la cascade ; au bout des 30 jours, le courriel habituel « fonctionnalité suspendue » part. Lever la suspension rend ce que la cascade
  avait pris, et rien d'autre : la cascade marque ses droits (`reference` `agency:<id>:suspended`), un droit suspendu par la plateforme
  pour une autre raison reste suspendu. Si le site a pris entre-temps le module d'une autre source (essai, abonnement), il garde le
  sien : c'est le droit de l'agence qui s'efface (le rendre annulerait l'autre).
- Suspendue, l'agence lit encore ; elle ne crée plus, ne règle plus de module et ne renvoie plus d'invitation.

### 8.5 Routes de l'agence (lot AG-2)

| Route | Qui | Rôle |
|---|---|---|
| `GET /api/agency/sites/availability?code=` | les deux rôles | `{ code, available, reason: INVALID \| RESERVED \| TAKEN \| null }` |
| `POST /api/agency/sites` | responsable | `{ code, name, adminEmail, adminFirstName?, adminLastName?, locale?, country?, province?, currency?, timezone?, modules? }` → 202 `{ request }` |
| `GET /api/agency/site-requests` | les deux rôles | `{ requests }`, de la plus récente à la plus ancienne |
| `GET /api/agency/site-requests/{id}` | les deux rôles | `{ request }` |
| `GET /api/agency/sites/{code}/modules` | les deux rôles | `{ modules: [{ key, label, enabled, byAgency, status, graceUntil }] }` |
| `PUT /api/agency/sites/{code}/modules/{feature}` | responsable | `{ enabled }` → `{ modules }` |
| `POST /api/agency/sites/{code}/invitation` | responsable | renvoie le lien de l'administrateur ; `{ adminEmail? }` désigne une autre personne → 202 `{ request }` |

`request` : `{ id, siteCode, name, adminEmail, status, errorCode, requestedAt, startedAt, finishedAt, site: { code, name, host } | null,
invitation, invitationAskedAt, modules: { opened, failed }, warnings }`. `GET /api/agency/me` rend aussi `sitesUsed` (sites gérés plus demandes en cours)
et `dailySiteRequests` ; `maxSites` peut valoir `null` (illimité) ; `GET /api/agency/sites` rend aussi `pending`. Un collaborateur qui
tente d'écrire reçoit 403 `AGENCY_MANAGER_ONLY`.

### 8.6 Routes de la plateforme (lot AG-2)

`PATCH /api/platform/agencies/{id}` `{ maxSites?: entier | null, dailySiteRequests?: 0 à 500 }` → `{ agency }` (un champ absent garde sa
valeur ; `maxSites` ne descend pas sous le nombre de places prises : `AGENCY_LIMITS`). `GET /api/platform/agencies/{id}` rend aussi
`requests`. Page « Plateforme : agences » : formulaire des limites, tableau des demandes de création.

Journal de l'agence (`agency_events`) : `site_requested`, `site_created`, `site_failed`, `site_admin_invited`, `site_module_opened`,
`site_module_closed`, `site_admin_changed`, `limits_changed` ; `suspended` et `resumed` portent le nombre de droits pris ou rendus. Aucune adresse de
courriel dans le journal.

### 8.7 Ce qu'un site neuf n'hérite plus (toute création de site, pas seulement par une agence)

Inventaire du 15/11/2026, fait sur une copie isolée du site de référence (en comptes, sans lire aucune valeur) : aucune clé de
paiement, aucun serveur de courriel, aucun jeton, aucune clé d'application ; mais un compte Boussole ESG avec son entreprise, et les
instantanés de stock de la gestion. Depuis ce lot, `createTenant` :

- **ne supprime plus jamais une base qui existait** : avant, l'échec de la copie sur une base déjà là passait par le `DROP DATABASE`
  du rattrapage ; choisir le nom d'une base existante l'effaçait. Désormais la base est vérifiée avant, et seule une base créée par
  l'appel est supprimée en cas d'échec ;
- **retire, de façon bloquante**, ce qui ne doit pas passer d'un site à l'autre : comptes Boussole et leurs entreprises, instantanés de
  stock, sessions, journal du site, configuration du courriel (l'expéditeur d'un site neuf vient de SA fiche), fiche d'entreprise et ce
  qui en dépend ; si la création échoue après la copie, la ligne du site part avec sa base ;
- **refuse la création** si le site de référence porte un compte qui n'est pas un compte de la plateforme ;
- copie la base nommée par `TENANT_TEMPLATE_DB` quand elle est réglée (les tests copient leur propre modèle de site,
  `db_site_template_test` ; une répétition copie une copie) ; en environnement de test, copier le site de référence est refusé.

### 8.8 Répétition avant la mise en ligne

La fabrication a été répétée sur une copie isolée du site de référence, jamais sur lui : serveur de bases à part, réseau sans la
production, commande `app:agency:sites:build`. La copie et son serveur sont détruits à la fin.

### 8.9 Le compte de la plateforme sur chaque site (décision du propriétaire, 15/11/2026)

Un site créé par une agence garde, comme tout site, le compte du propriétaire de la plateforme (`ROLE_SUPER_ADMIN`), seul moyen d'accès
de la plateforme pour dépanner tant qu'AG-3 n'existe pas. Trois garde-fous, sur **tous** les sites (`PlatformAccessJournal`) :

1. **Visible** : dans la liste des utilisateurs du site (écran Utilisateurs, colonne Applications ; `GET /api/memoires/admin/users`,
   champ `platformAccount`), ce compte porte la mention « Compte de la plateforme, pour le dépannage ». L'administrateur du site le
   voit ; il ne peut ni le modifier ni le supprimer (gardes existantes de l'écran et des routes).
2. **Écrit** : la clause « la plateforme peut accéder au site pour le dépannage et la sécurité » s'ajoute aux conditions. **À porter
   dans la liste remise à l'avocat**, avec les points d'IMP-2.
3. **Tracé** : chaque connexion de ce compte sur un site par l'API (une fois le code à usage unique validé), et chaque entrée dans
   l'espace d'administration d'un site, laisse une ligne dans le journal de CE site (`content_audit_log`, ressource `platform_access`,
   action `login`) : le compte, la date, par où. Dans l'espace d'administration, une même session passe d'un site à l'autre sans
   nouvelle connexion (`/admin?tenant=<code>`) : la trace tient donc à la première page ouverte sur chaque site, par session
   (`PlatformAccessSubscriber`), une fois le code validé. La ligne est écrite hors de l'ORM : une panne du journal ne fait pas
   échouer la connexion. Ni adresse IP ni appareil. L'administrateur du site la lit sur la page « Accès de la plateforme »
   (`/admin/acces-plateforme`) et par `GET /api/landingpage-audit?resource=platform_access`. Le renouvellement d'une session n'est pas
   une connexion. Lignes gardées 180 jours, comme tout le journal du site.

Le site de référence porte deux comptes de ce rôle : le propriétaire regarde lui-même le second.

Depuis le lot CONSTRUCTION (16/11/2026, `docs/boutique-en-construction.md`), un site créé par une agence avec le module Boutique
naît « en construction », fermé en entier (son état de naissance est posé à la fin de la fabrication, `StorefrontService::born` ;
sans le module Boutique, le site est en service aussitôt) : l'agence lit son état, l'ouvre (`POST /api/agency/sites/{code}/storefront/open`) et obtient un lien d'aperçu
(`POST …/storefront/preview-link`). La création d'un site retire aussi le compte de paiement que le modèle porterait.

### 8.10 Limites connues

- Un administrateur de site peut demander lui-même l'essai d'un module que l'agence a fermé (chemin « Demander » de la plateforme) :
  l'agence ne règle que les droits qu'elle a posés. À revoir avec les prix (AG-5).
- Détacher de l'agence un site qu'elle a créé laisse ses droits `agency` tels quels : la cascade ne le couvre plus. C'est à la
  plateforme de les reprendre à la main.
- Supprimer un site créé reste un geste de la plateforme, hors de ce lot.
- La caisse par code PIN n'est pas un chemin d'entrée du compte de la plateforme : elle est refusée aux administrateurs.
- Le verrou de fabrication est un verrou de session de la connexion maître : si cette connexion est rouverte au milieu d'une
  fabrication, il tombe ; une seconde copie simultanée échouerait alors (`BUILD_FAILED`), sans rien détruire.
- Ce qu'un site neuf garde du site de référence hors des tables nettoyées (réglages de transporteur, référentiels) n'a pas été relu
  ligne à ligne : l'inventaire a cherché, par leur nom, les colonnes de clés, de jetons et de mots de passe, et n'en a trouvé aucune
  renseignée hors des comptes.
- Aucune agence n'existe en production : la première sera une agence de test, à la campagne de tests.

### 8.11 Depuis la console du serveur (16/11/2026)

`app:platform:site:request` (TYPE-1, au nom de la plateforme : `--type normal|agency --code --name --admin-email [--agency-name --test]`)
et `app:agency:site:request` (AG-2, au nom d'une agence : `--agency <code> --code --name --admin-email`) déposent une demande par les
MÊMES services et contrôles que leurs routes, tracée « console du serveur » ; aucune route ne les expose ; un site existant est refusé ;
`--admin-email=-` lit l'adresse sur l'entrée standard ; la sortie ne montre ni lien, ni mot de passe, ni l'adresse en clair (masquée).
La fabrication suit par le worker, ou `app:agency:sites:build`.

## 9. Lot TYPE-1 : le type d'un site dès sa création (16/11/2026)

Code : `src/Services/Platform/Sites/` (`PlatformSiteService`, `PlatformSiteRequestRepository`), `src/Controller/PlatformController/
PlatformSitesController.php`, `src/Controller/Admin/PlatformSitesAdminController.php`. Tests : `tests/Functional/Platform/PlatformSitesTest.php`.
Base maître : `ops/sql/platform_sites_master.sql` (une table, `platform_site_requests`).

### 9.1 Le principe

Le super administrateur crée un site en choisissant son **type** : `normal` ou `agency`. Le type est un RACCOURCI qui enchaîne des
gestes existants, pas un champ enregistré : il reste porté par les liens de la base maître, et les pastilles en découlent. Une seule
logique sert la route de l'API et l'écran « Plateforme : sites » d'EasyAdmin (le panneau du front la reprendra). L'ancien formulaire
`/setup/new-store`, protégé par une clé, reste tel quel. Le type « groupe » viendra avec le lot qui crée le groupe
(`docs/plan-entree-et-groupe.md`).

Une demande est une ligne de la base maître, écrite AVANT sa première étape, avec ses étapes et l'état de chacune (`todo`, `done`,
`failed`, `waiting`, `skipped`) :

| Étape | Ce qu'elle fait | Rejouée |
|---|---|---|
| `site` | la fabrication d'AG-2 (§ 8.2), sans agence : copie du site de référence sous le verrou commun des fabrications, comptes hérités nettoyés, fiche neuve, modules cochés posés comme des **accords de la plateforme** (source `manual`, sans essai automatique, sans courriel), site né en construction s'il a la boutique | jamais refaite une fois faite |
| `invitation` | l'administrateur désigné est invité par courriel (lien de 7 jours). Le courriel ne nomme aucune agence | un nouveau lien, l'ancien ne vaut plus |
| `agency` (type agence) | la fiche d'agence naît avec ce site pour attache, par le geste d'AG-1 (§ 7) ; les jours d'essai et le nombre de sites du formulaire remplacent ceux du forfait d'essai | si la fiche existe déjà pour ce site, rien n'est doublé |
| `manager` (type agence, case cochée par défaut) | le rôle de responsable de l'agence est donné à l'administrateur **quand il accepte son invitation**, pas avant : l'étape attend (`waiting`). Il va au **compte qui a accepté**, désigné par son identifiant (noté sur la demande, `result.acceptedUserId`), jamais à un autre compte qui porterait la même adresse | donné à l'acceptation (`/api/password-reset/confirm`) ; si les étapes ne sont pas finies, à leur fin ; sinon au rattrapage (lecture de la liste, passage horaire, `app:agency:sites:build`), qui ne sert que les acceptations NOTÉES : un compte seulement « vérifié » ne suffit jamais |

États d'une demande : `pending` (en attente), `building` (en cours), `ready` (faite ; le rôle de responsable peut encore attendre),
`incomplete` (**à compléter** : une étape a échoué APRÈS la création du site), `failed` (échec AVANT que le site existe : aucun site
n'a été créé, on redemande ; seule exception, `INTERRUPTED_DATABASE_LEFT` : une copie coupée a laissé une base de ce nom, qui retient
le code et se regarde à la main), `abandoned`.

- Rien n'est défait en silence : une demande à compléter garde l'étape en cause (`failedStep`) et son code (`errorCode`), et le site
  porte la pastille « Création à compléter ».
- **Reprendre** rejoue les seules étapes manquantes. Elle peut désigner une autre adresse pour l'administrateur tant que son
  invitation n'est pas partie (adresse fausse, ou déjà celle d'un compte du site neuf : `ACCOUNT_EXISTS`) ; le changement se fait en
  une instruction conditionnée à l'état (deux reprises au même instant : une seule passe), et efface une acceptation notée pour
  l'ancienne adresse. Si la personne a déjà choisi son mot de passe (`ALREADY_ACTIVE`), il n'y a plus personne à inviter : l'étape
  est faite, sans courriel ; et elle ne se remplace plus par la demande (409 `PLATFORM_SITE_ADMIN_ACTIVE`, comme pour une agence) :
  elle administre le site, la remplacer est l'affaire du site.
- **Abandonner** (demande à compléter, ou pas encore commencée) : le site, s'il existe, reste un site normal, ni défait ni supprimé ;
  la demande le dit (`site`), y compris pour un site laissé par une fabrication coupée.
- **Ne plus attendre** (même route que « Abandonner », sur une demande FAITE dont le rôle de responsable attend) : le rôle ne sera
  plus donné par cette demande (`manager` passe à `skipped`) ; le site, l'agence et la demande restent tels quels. Une acceptation
  tardive n'est alors plus notée.
- **Demande interrompue.** Le passage horaire (`app:gestion:notifications:envoyer`, à côté de celui des agences) et
  `app:agency:sites:build` rendent visible une demande restée « en cours » plus de 15 minutes (son worker est tombé) : « à compléter »
  si le site existe, « en échec » sinon, code `INTERRUPTED` (`INTERRUPTED_DATABASE_LEFT` si une base de ce nom est restée sans
  site) ; le site de référence, fermé le temps d'une copie, est rouvert. Une erreur imprévue pendant le traitement a le même effet,
  tout de suite. Au passage horaire, une demande en attente créée depuis plus de 5 minutes est confiée de nouveau au worker.
- **Site laissé par une fabrication coupée ou en échec après sa naissance** (le site existe, son étape n'est pas notée faite) : on ne sait pas jusqu'où la
  fabrication est allée (comptes hérités nettoyés ou non). « Reprendre » ne l'adopte pas et ne le refait pas : `SITE_TO_CHECK`, la
  demande reste à compléter. La plateforme regarde ce site, puis abandonne la demande (le site reste) ou le supprime.
- Une demande vivante (en attente, en cours, à compléter) retient son code : ni la plateforme ni une agence ne le redemandent.

### 9.2 Routes (`ROLE_SUPER_ADMIN`, règle `^/api/platform`)

| Route | Rôle |
|---|---|
| `POST /api/platform/sites` | `{ type: normal \| agency, code, name, adminEmail, adminFirstName?, adminLastName?, locale?, modules?, agency?: { name, code?, contactEmail?, isTest?, trialDays?, maxSites?, managerRole? } }` → 202 `{ request }`. `modules` absent : page publique et boutique |
| `GET /api/platform/sites/badges?badge=` | les sites et leurs pastilles → `{ sites: [{ code, name, host, badges: [{ type, agencyId?, agencyCode?, agencyName?, requestId? }] }], badges }` |
| `GET /api/platform/site-requests`, `GET …/{id}` | `{ id, type, siteCode, name, adminEmail, status, steps, failedStep, errorCode, agencyId, site, invitation, modules, warnings, requestedAt, startedAt, finishedAt }` |
| `POST /api/platform/site-requests/{id}/resume` | corps facultatif `{ adminEmail }` → 202 |
| `POST /api/platform/site-requests/{id}/abandon` | → 200. Sur une demande faite dont le rôle de responsable attend : « ne plus attendre » |

`GET /api/platform/sites` reste la grille sites × fonctionnalités de la console de pilotage : la liste à pastilles a sa propre adresse.
Refus : 422 `VALIDATION_FAILED { errors: [{ path, message }] }`, 409 `PLATFORM_SITE_CODE_RESERVED`, `PLATFORM_SITE_CODE_TAKEN`,
`PLATFORM_SITE_REQUEST_STATE`, `PLATFORM_SITE_ADMIN_ACTIVE`, 404 `PLATFORM_SITE_REQUEST_NOT_FOUND`. Codes d'une étape en échec : `SITE_CODE_TAKEN`, `BUILD_FAILED`,
`ACCOUNT_EXISTS`, `EMAIL_FAILED`, `NO_LINK`, `INVITATION_FAILED`, `INTERRUPTED`, `INTERRUPTED_DATABASE_LEFT`, `SITE_TO_CHECK`, et les codes d'agence
(`AGENCY_CODE_TAKEN`…).

### 9.3 Pastilles

Calculées à la lecture depuis les liens de la base maître, jamais depuis un champ « mode » ; un site peut en porter plusieurs, et la
liste se filtre :

| Pastille | Lien |
|---|---|
| `independent` (« Indépendant ») | aucun lien d'agence |
| `agency_home` (« Agence X ») | le site est le site d'attache d'une agence non fermée |
| `agency_managed` (« Géré par l'agence X ») | le site est géré par une agence non fermée |
| `creation_incomplete` (« Création à compléter ») | une demande de création de ce site reste à compléter |

« Groupe X » et « Site principal » viendront avec le lot du groupe.

### 9.4 Écran

EasyAdmin, super administrateur : **« Plateforme : sites »** (`/admin/plateforme/sites`) : la liste filtrable, le formulaire de création
(type, code, nom, administrateur, langue, modules, et le bloc de l'agence), et les demandes avec leurs étapes, « Reprendre »,
« Abandonner » et « Ne plus attendre ».

### 9.5 Limites connues

- Les fabrications d'agence et de la plateforme partagent un seul verrou : une copie du site de référence à la fois.
- Une fabrication interrompue n'est rendue visible qu'au passage horaire qui suit ses 15 minutes ; d'ici là la demande se lit « en
  cours », et le site de référence, si la copie l'avait fermé, reste fermé (jusqu'à une heure et quart). Le site ou la base qu'elle a
  pu laisser est à regarder à la main (`SITE_TO_CHECK`, `INTERRUPTED_DATABASE_LEFT`, § 9.1).
- **Qui reçoit le rôle de responsable.** Le compte du site d'attache né de l'invitation d'administrateur, qui porte l'adresse désignée
  au moment où son mot de passe est choisi par un lien de réinitialisation. La demande ne distingue pas le lien d'invitation d'un lien
  « mot de passe oublié », et un administrateur du site d'attache qui change l'adresse de ce compte peut s'en servir à sa place : c'est
  la limite d'AG-1 (un administrateur du site d'attache agit déjà sur les comptes de ce site). Durcissement proposé à part : effacer
  le lien en attente quand l'adresse d'un compte change.
- « Abandonner » envoyé au moment où le worker finit la demande devient « Ne plus attendre » : le site et l'agence restent, le rôle
  de responsable n'est plus attendu (il se donne alors par la page « Plateforme : agences »).
- Le rôle de responsable n'est donné qu'au compte qui accepte l'invitation de la demande ; si une autre personne doit l'être, la page
  « Plateforme : agences » le fait. Une acceptation faite hors du lien d'invitation et du mot de passe oublié (compte vérifié à la
  main dans le site) n'est pas notée : le rôle se donne alors par la même page.
- L'adresse de l'administrateur désigné se lit dans la demande (base maître) tant que la demande existe : pas de purge dans ce lot.
- Les journaux écrits par ce lot ne portent ni l'adresse de l'administrateur désigné ni celle de l'auteur d'un geste (le journal
  commun des envois de courriel garde, lui, le message d'erreur du serveur d'envoi).
- Le pays, la devise et le fuseau du site neuf suivent le modèle : ils se règlent ensuite dans le site.

## Tenir cette fiche à jour

À chaque décision du propriétaire, la reporter au § 1 ou au § 6. À chaque lot codé, le dire en tête et dans le
tableau des lots, avec le contrat du lot.
