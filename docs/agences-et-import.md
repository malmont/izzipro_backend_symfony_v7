# Agences et import d'une boutique existante : plan

**État : PLAN, aucun code.** Écrit le 15/11/2026 à la demande du chef de projet, d'après les décisions du propriétaire du même jour.
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

**Recommandation : B.** Tout ce qui protège une connexion existe déjà et reste unique. L'agence a de toute façon besoin d'un site (sa
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
mention de l'agence. Un opérateur ne reçoit aucun courriel du site et ne compte pas parmi ses clients.

### 3.6 Marque blanche, niveau a

Une seule source : la marque de l'agence (`agencies`), lue par un service unique pour tout site géré. Elle remplace le nom et le logo de
la plateforme dans l'en-tête de l'espace d'administration (EasyAdmin et web) et dans les courriels du serveur (nom d'expéditeur,
signature, adresse d'assistance). L'adresse technique d'envoi reste celle de la plateforme, pour que les courriels arrivent. Le nom du
commerce, lui, reste celui de la fiche du site.

### 3.7 Prix, relevé, essai, suspension

- Le relevé du mois se calcule : pour chaque site géré, le prix de gros du palier atteint, plus chaque option ouverte, plus les unités IA
  consommées au-delà de ce que le forfait comprend. Un mois entamé se compte au prorata des jours.
- Offre de départ : à la création, l'agence est en essai (60 jours, 3 sites, tous les modules). Rappels 7 jours et 1 jour avant la fin.
- Sans forfait payé à la fin : délai de grâce, puis suspension de l'agence, qui suspend les droits de source `agency` de ses sites.
  Le super administrateur peut aussi suspendre un seul site.
- Le paiement lui-même (carte, virement) : voir la question 5.

### 3.8 Écrans

| Qui | Où | Quoi |
|---|---|---|
| Super administrateur | EasyAdmin, écran Plateforme, onglet « Agences » ; `/api/platform/agencies` | liste, création, forfait et prix négociés, sites rattachés, suspension, relevés, journal |
| Agence | web (l'application de gestion clonée), « Espace agence » ; `/api/agency/*` | tableau de ses sites (état, modules, fin d'essai, alertes), créer un site, options d'un site, marque, collaborateurs, relevés, « Ouvrir ce site » |
| Commerçant | EasyAdmin et web de son site | bandeau « Géré par … », compte de l'opérateur visible dans Utilisateurs |

### 3.9 Lots

| Lot | Contenu | Risque |
|---|---|---|
| AG-1 | Tables maîtres, agence et forfait créés par le super administrateur, rattachement de sites existants, rôles sur le site d'attache, lecture `GET /api/agency/me` et `/sites` | comptes et droits |
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
| Variant Inventory Qty | stock de départ (question 9) |
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
- Un client qui n'accepte pas dans le délai est effacé (question 10).

### 4.4 Historique des commandes

En dernier. Les commandes importées vont dans une archive à part : visibles dans la fiche du client et dans une série « ventes
d'avant » des statistiques, jamais dans le journal de caisse, la numérotation des factures, les taxes ni les exports comptables.

### 4.5 Lots

| Lot | Contenu | Risque |
|---|---|---|
| IMP-1 | Catalogue Shopify : dépôt, analyse, confirmation, exécution, images, rapport, annulation | données |
| IMP-2 | Clients avec consentement | données personnelles |
| IMP-3 | Historique des commandes | données |
| IMP-4, IMP-5 | Lecteurs WooCommerce, puis PrestaShop | faible |

IMP-1 ne dépend pas d'AGENCE : il peut passer avant ou entre deux lots d'agence.

## 5. Risques

| Risque | Parade prévue |
|---|---|
| Une agence voit ou modifie le site d'une autre | l'agence se lit du site d'attache, jamais d'un paramètre ; tests de cloisonnement à chaque route ; relecture indépendante |
| Le code de passage volé ou rejoué | usage unique, une minute, lié au collaborateur et au site, journal des deux côtés |
| La création de sites en rafale bloque la base modèle | file avec un worker, une création à la fois, limite par agence |
| Un relevé faux | lignes figées à la clôture du mois, calcul rejouable, tests sur les prorata et les paliers |
| Une suspension coupe la page publique d'un commerçant | voir la question 6 : la page publique peut rester en ligne |
| Données personnelles importées sans base légale | comptes invités, rien d'actif sans acceptation, attestation du commerçant, effacement au délai |
| Fichier d'import piégé (HTML, adresses d'images) | HTML nettoyé, images prises sur une liste de serveurs permis, plafonds de taille |
| Doublons de codes-barres | règle de CB-1, déjà garantie par la base |

## 6. Questions pour le propriétaire

1. **L'agence est d'abord un site** (§ 3.1, option B) : ses collaborateurs se connectent sur le site de l'agence. D'accord ?
2. Le site de l'agence est-il gratuit, et compte-t-il parmi les 3 sites de l'offre de départ ? (Proposition : gratuit, hors compte.)
3. Qui ouvre une agence : le super administrateur sur demande, ou l'agence seule par un formulaire ? (Proposition : sur demande.)
4. Les prix : paliers du prix de gros (la fourchette du 06/11 était 169 à 269 $ CA par site selon le nombre de sites), prix de chaque
   option, prix et nombre d'unités IA comprises, unités offertes pendant l'essai.
5. Le paiement : au départ un relevé mensuel réglé hors de la plateforme (virement ou facture), marqué « payé » par le super
   administrateur, ou tout de suite un prélèvement par carte ? (Proposition : le relevé d'abord.)
6. À la fin des 60 jours sans forfait payé : combien de jours de grâce, et la page publique du commerçant reste-t-elle en ligne pendant
   la suspension ? (Proposition : 7 jours, page publique gardée 30 jours.)
7. Le commerçant voit-il le nom de la plateforme quelque part (« propulsé par … ») ou jamais ?
8. L'agence entre dans le site de son client comme administrateur, et le commerçant le voit sans pouvoir le couper (seule la plateforme
   détache un site). D'accord ? Une clause des conditions doit le dire (Loi 25).
9. Import : les quantités en stock du fichier deviennent-elles le stock de départ ? Les commandes importées comptent-elles dans les
   statistiques (comparaison avec l'an dernier) ?
10. Import des clients : combien de temps garder un client invité qui n'a pas accepté ? (Proposition : 90 jours.)
11. L'ordre : IMP-1 (catalogue) avant AG-1, ou les agences d'abord ?

## Tenir cette fiche à jour

À chaque décision du propriétaire, la reporter au § 1 ou rayer la question du § 6. À chaque lot codé, le dire en tête et dans le
tableau des lots, avec le contrat du lot.
