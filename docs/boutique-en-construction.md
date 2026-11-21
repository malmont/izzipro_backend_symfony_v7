# Boutique en construction (lot CONSTRUCTION, 16/11/2026)

Sur le modèle des boutiques en ligne du marché : tant que la boutique d'un site n'a pas été ouverte par son administrateur, le public
n'y voit que « Bientôt ouvert », au nom de la boutique. Besoin validé par le propriétaire le 16/11/2026 ; contrat des réponses validé
par le chef de projet le même jour.

Décisions du propriétaire (16/11/2026), à ne pas défaire :

- **Un site né sans le module Boutique n'est jamais bloqué.** Sur un site en service, la construction ne vaut que si le module
  Boutique est actif.
- **Un site neuf né avec sa boutique reste fermé en entier jusqu'au geste explicite « Ouvrir »** : éteindre ou suspendre le module
  Boutique (agence suspendue, abonnement impayé) ne l'ouvre pas. Si son administrateur éteint la boutique pour n'en faire qu'un site
  d'applications, il l'ouvre d'un geste, sans moyen de paiement (décision du chef de projet, 16/11/2026).
- Elle ne ferme que **la boutique en ligne et le site vitrine** : catalogue, panier, commande, inscription d'un client de la
  boutique, formulaires de contact et de réservation, ancienne boutique du serveur.
- **Mémoires Vivantes, Living Memory et Boussole sont indépendantes de la boutique** : la construction ne les ferme jamais. Un compte
  créé par elles n'ouvre pas la boutique.
- Un **site neuf** naît fermé en entier. Si le module Boutique est activé plus tard sur un **site déjà en service**, la boutique
  seule naît en construction : rien d'autre du site ne se ferme.
- Les sites qui existaient avant ce lot restent ouverts : rien ne change pour eux.

Code : `src/Services/Storefront/StorefrontService.php` (état, étendue, liste de contrôle, ouverture, lien d'aperçu),
`src/Security/StorefrontGateSubscriber.php` (ce que le serveur ferme), `src/Security/StorefrontViewer.php` (qui est de l'équipe),
`src/Controller/StorefrontController/`, `src/Controller/Admin/StorefrontAdminController.php`. Tests :
`tests/Functional/Storefront/StorefrontTest.php`, et `tests/Functional/Platform/AgencySitesTest.php` pour un site neuf.

## 1. L'état gardé et l'étendue

Quatre colonnes sur la fiche du site (`entreprise`), lues et écrites en SQL direct : `storefront_state`, `storefront_opened_at`,
`storefront_preview_hash`, `storefront_preview_expires_at`. Migration `Version20270115100000`, script
`scripts/migrate_all_v2_construction.sh` (hors dépôt).

L'étendue est **calculée** depuis la fiche (`StorefrontService::closureOf`) : il n'y a aucun passage d'état à l'activation ou à
l'extinction d'un module, donc rien à rater entre deux écritures.

| État gardé | Module Boutique | Heure d'ouverture de la boutique | Étendue |
|---|---|---|---|
| `construction` (site neuf, jamais ouvert) | actif, éteint ou suspendu | — | **`site`** : boutique ET site vitrine fermés |
| `open` (site en service) | actif | vide : boutique jamais ouverte | **`shop`** : la boutique seule est fermée |
| `open` | actif | posée : boutique ouverte une fois | ouvert |
| `boutique` (boutique refermée) | actif | — | **`shop`** |
| `open` ou `boutique` | éteint | — | rien n'est fermé |

Qui écrit l'état :

- **Site existant** : la colonne naît à `open` ; la migration pose l'heure d'ouverture sur toute boutique ACTIVE ce jour-là (tenue
  pour « déjà ouverte »), AVEC la colonne et une seule fois : rejouée, elle ne touche plus rien (une boutique active sans heure
  d'ouverture est ensuite une boutique en construction, à ne pas ouvrir). Aucun site existant n'est fermé par la migration ; le
  script s'arrête si une boutique active d'un site existant reste sans heure.
- **Site neuf** : `TenantConnectionManager::createTenant` règle la valeur par défaut de la colonne, dans la base de CE site, sur
  `construction` : sa fiche, quel que soit le chemin qui la crée, naît fermée en entier. Une fois ses modules posés, sa fabrication
  pose son état de naissance (`StorefrontService::born`, appelé par `AgencySiteBuilder` et par `TenantSetupFeaturesService`) :
  `construction` avec sa boutique, `open` s'il naît SANS elle (jamais bloqué). Si la boutique était demandée mais que son droit
  n'a pas pu être posé, le site reste `construction` : jamais servi par défaut.
- **Ouvrir** (`open`) : état `open` ; l'heure d'ouverture n'est posée que si la boutique est active (c'est l'heure de la BOUTIQUE),
  d'après la boutique lue par la liste de contrôle, celle dont le paiement vient d'être vérifié.
  Un site ouvert sans boutique garde donc une heure vide : si la boutique lui vient plus tard, elle naît en construction.
- **Refermer** (`close`) : état `boutique`, pour une boutique ouverte seulement ; le site vitrine reste servi. Sans module Boutique,
  ou déjà en construction : rien n'est écrit.
- **Module Boutique activé plus tard** sur un site en service : rien n'est écrit ; la boutique est en construction parce qu'elle n'a
  pas d'heure d'ouverture. Une boutique déjà ouverte une fois n'est pas refermée par un module éteint puis rallumé.
- **Module Boutique éteint ou suspendu** : rien n'est écrit. Un site neuf jamais ouvert reste fermé en entier ; un site en service
  n'a simplement plus de boutique.
- Un site sans fiche suit la valeur par défaut de sa base. Une base pas encore migrée ou injoignable est tenue pour « ouverte »
  (jamais un site existant fermé par accident), sans que cette réponse soit gardée en cache.
- L'état est gardé en cache 15 secondes côté serveur ; tout geste, et toute écriture de la fiche, vide le cache du site.

## 2. Ce que le visiteur obtient

`GET /api/site/storefront` (public, lu hors du pare-feu de l'API : un jeton expiré ne coûte pas l'état au visiteur) →
`{ state: construction | open, scope: site | shop | null, name, locale, openedAt, preview: { active, expiresAt } }`. `state` vaut
`construction` dans les deux étendues ; `scope` dit ce qui est fermé. Site en service sans module Boutique : `state: open`,
`scope: null`.
`Cache-Control` de 15 secondes (`public` pour un visiteur, `private` dès qu'un compte, un cookie ou un jeton d'aperçu est en jeu).
Tant que cette route n'existe pas sur un serveur (404), le front se comporte comme pour `open`.

Le serveur ferme lui-même ce que la page « Bientôt ouvert » cache (`StorefrontGateSubscriber`). La règle est **fermée par défaut**
sur ce périmètre : toute route est tenue pour une route de la BOUTIQUE, sauf celles que quatre listes rangent ailleurs. Une route
nouvelle que personne n'a rangée est donc fermée en construction. (La première écriture du lot fermait une liste de chemins ; la
relecture indépendante l'a contournée par une autre adresse de la fiche d'un article, un suffixe `.json` et des pages hors de
`/api` : d'où cette règle.)

| Classe | Quoi | Étendue `site` | Étendue `shop` |
|---|---|---|---|
| `KEPT` | connexion, mot de passe, code à usage unique, devises, découverte et état du site, fiche du site, fichiers (logo), espace d'administration, création de site, entrée des applications de l'équipe (activation, profil, thème, PIN de caisse), webhooks, déploiement des réglages, boîtiers | servi à tous | servi à tous |
| `APPS` | toutes les routes `/api/memoires` et `/api/boussole` ; routes de compte communes (`/api/account`, `/api/profile`, `/api/notifications`, `validate-token`, `resend-verification`, `stripe-config`, `/verify/email`) ; conditions de Living Memory et de la caisse ; reçu numérique de la caisse (`/api/r/{jeton}`) ; inscription et adhésion quand elles ne visent pas la boutique | comme sur un site ouvert | comme sur un site ouvert |
| `SHOWCASE` | le site vitrine : réglages de la page, présentations, bannières, vidéos, médias partagés, équipe, emplois, carrousel des véhicules (`/api/vehicles/carousel`), offres, formulaires. Rien de la boutique : `/api/landingpage` (articles de la page d'accueil), `/api/customization` (options et prix d'un article), `/api/booking` (disponibilité d'une location) et `/api/vehicle_products` (véhicules en vente ou en location, depuis le lot VEHICULES-PUBLIC du 21/11/2026) sont des routes de la boutique | **fermé** | servi |
| tout le reste | la boutique : catalogue, réglages de la boutique, commandes, abonnements, comptes clients de la boutique, pages de l'ancienne boutique | **fermé** | **fermé** |

Pour une route fermée :

| Qui | Réponse |
|---|---|
| visiteur, compte client (même né par Mémoires Vivantes, Living Memory ou Boussole) | 403 `STOREFRONT_UNDER_CONSTRUCTION { name, scope }` (une page sobre « Bientôt ouvert » pour une page du serveur) |
| compte d'**équipe** connecté (administrateur, assistance, caisse, biographe, agence…) | servie |
| jeton d'aperçu valable (en-tête `X-Storefront-Preview`), en lecture (`GET`, `HEAD`) | servie ; jamais une écriture ; l'aperçu voit ce que verra le public : un article hors ligne y est absent (lot FICHES-HORS-LIGNE, `docs/boutique.md`) |

Écritures fermées à **tous**, équipe et aperçu compris :

- dans les deux étendues (`SHOP_ORDERS`, `SHOP_ORDER_PAGES`, `SHOP_SIGNUP`) : commander, chiffrer un panier, payer, s'abonner, donner
  un avis, accepter une invitation de client repris, s'inscrire à la boutique (`POST /api/register` quand le champ `app` vise la
  boutique, ou est absent ou inconnu), adhérer à la boutique (`POST /api/apps/join` quand le JETON de passage est de la famille
  boutique : jamais d'après un champ du corps), pages de l'ancienne boutique (elles écrivent aussi sur une simple lecture : fermées
  quelle que soit la méthode) ;
- dans l'étendue `site` seulement (`SHOWCASE_ORDERS`) : formulaire de contact, infolettre, réservation, formulaire à étapes,
  candidature, financement.

À savoir :

- `/api/reservations` sert deux choses : le formulaire de réservation du site vitrine et les rendez-vous de Mémoires Vivantes. Une
  demande qui porte un livre (`book_id`, dans l'adresse ou le corps), la liste des rendez-vous du compte et leur confirmation ne
  sont jamais fermées (`StorefrontGateSubscriber::memoiresAppointment`) ; les services proposés, les créneaux et la réservation
  sans livre suivent le site vitrine.
- `GET /api/entreprise/{id}` : dans l'étendue `site`, le visiteur et le compte client la reçoivent **réduite** à l'identité légale,
  aux textes légaux, au logo et aux indicateurs des applications (`StorefrontService::LEGAL_FIELDS`) ; même forme, le reste vide.
  Dans l'étendue `shop`, elle est entière : le site est en service. Le profil d'application (`/api/app-profile/{app}`) cache de même
  son texte de présentation dans l'étendue `site`, sauf pour les profils de Mémoires Vivantes, Living Memory et Boussole.
- La vente en magasin (`POST /api/order/create-multi-payment`, routes `/api/pos`) n'est pas la boutique publique : la caisse vend sur
  un site en construction. La commande d'essai de la boutique n'existe pas : elle est notée pour plus tard.
- Le compte d'équipe est reconnu même sur les routes que le pare-feu de l'API ne couvre pas (`StorefrontViewer` : le jeton du site y
  est lu comme par le pare-feu, les rôles relus en base).
- Toute réponse d'une route FERMÉE (refus, lecture d'équipe, lecture d'aperçu) part en `Cache-Control: private, no-store`. Une route
  qui n'est pas fermée garde son cache habituel.
- `StorefrontTest::testEveryRouteOfTheApplicationIsFiledAndOnlyTheShopAndTheShowcaseCloseUnderConstruction` relit TOUTES les routes
  de l'application, dans les deux sens : toute route de Mémoires Vivantes, Living Memory et Boussole est ouverte ; tout le reste est
  fermé pour un site neuf, sauf les routes de `KEPT` et les routes de compte de `APPS`, écrites en toutes lettres dans l'essai ;
  les routes de `SHOWCASE`, servies quand la boutique seule est en construction, y sont écrites en toutes lettres aussi.

## 3. Voir la vraie boutique avant l'ouverture

- Un **compte d'équipe connecté** du site (administrateur, assistance, caisse…) : `preview.active` vaut vrai, les lectures sont servies.
- Un **lien d'aperçu** : `https://<adresse du site>/apercu#token=<64 caractères>`, valable 7 jours ; un nouveau lien remplace l'ancien ;
  il est retiré à l'ouverture et quand la boutique est refermée. Le jeton est dans le fragment de l'adresse, jamais dans les
  paramètres. Le front le garde pour la session du navigateur et l'envoie dans l'en-tête `X-Storefront-Preview`. Contrôle :
  `POST /api/site/storefront/preview { token }` → `{ valid: true, expiresAt }` ou 400 `PREVIEW_LINK_INVALID`. Le lien ouvre les
  LECTURES seulement. Quand rien n'est fermé (boutique ouverte, site en service sans boutique) : 409 `STOREFRONT_ALREADY_OPEN`.
- La base ne garde que l'empreinte du jeton. Ni le jeton ni l'en-tête ne sont écrits dans un journal : le lien n'est rendu qu'une
  fois, à sa création.

## 4. Ouvrir la boutique

Administrateur du site (`ROLE_ADMIN`) :

| Route | Rôle |
|---|---|
| `GET /api/site/storefront/checklist` | `{ state, scope, shop, openedAt, canOpen, items: [{ key: products \| payment \| terms \| unbuyable, ok, blocking, message }] }` ; `shop` : le module Boutique est actif. Correctif du 16/11/2026 : `products` compte un article en ligne ACHETABLE (règle du panier, `SellableProduct` : une variante dont le prix, ou à défaut celui de l'article, est > 0) ; `unbuyable` (avertissement, jamais bloquant) compte les articles en ligne qui ne s'achètent pas (sans variante, ou sans aucun prix) ; `terms` exige les conditions de vente dans chaque langue que sert le site (`LegalTextLanguages`) ; `payment` : Stripe seul (Square n'encaisse rien) |
| `POST /api/site/storefront/open` | `{ state: open, scope: null, openedAt }`, ou 409 `STOREFRONT_NOT_READY { items }` |
| `POST /api/site/storefront/close` | `{ state: construction, scope: shop, openedAt: null }` : la boutique seule se referme |
| `POST /api/site/storefront/preview-link` | `{ url, expiresAt }` |

Sans module Boutique (`shop: false`), `items` est vide. Site en service : `state: open`, `canOpen: false`, il n'y a rien à ouvrir
et aucun geste n'écrit. Site neuf jamais ouvert dont la boutique est éteinte : `state: construction`, `scope: site`,
`canOpen: true` : il s'ouvre d'un geste, sans moyen de paiement.

Les mêmes gestes sont dans l'espace d'administration du serveur, page **« Ouverture du site »** (`/admin/ouverture-du-site`, menu
« Mon site ») : état, liste de contrôle, « Ouvrir la boutique », « Refermer la boutique », « Créer un lien d'aperçu » (le lien est
rendu dans la réponse du geste, une fois ; il n'est gardé ni en session ni ailleurs). Les écrans de « appli de gestion web »
viendront ensuite.

La liste de contrôle avertit sans bloquer (articles visibles, conditions de vente remplies), sauf **sans aucun moyen de paiement**
(un compte de paiement connecté ET actif) quand la boutique est active. Chaque geste est au journal du site (`content_audit_log`, ressource `storefront` : `open`,
`close`, `preview_link`).

Agence, pour un site qu'elle a **créé** (`docs/agences-et-import.md` § 8) : `GET /api/agency/sites/{code}/storefront` (les deux rôles),
`POST …/storefront/open` et `POST …/storefront/preview-link` (responsable). C'est le lien d'aperçu qui lui montre la boutique : elle
n'a pas de compte dans le site de son client. Une agence suspendue lit encore, mais n'ouvre plus.

## 5. Fronts

- **boutique next** (livré le 16/11/2026, à reprendre pour `scope`) : page « Bientôt ouvert », noindex, page `/apercu` qui lit le
  jeton, en-tête `X-Storefront-Preview`, bandeau d'aperçu, commande et formulaires masqués en aperçu ; pages légales depuis la fiche
  réduite. Dans l'étendue `shop`, seules les pages de la boutique montrent « Bientôt ouvert ».
- **appli de gestion web** : écrans de l'administrateur (liste de contrôle, ouvrir, refermer, lien d'aperçu) et boutons de l'espace
  d'agence.
- **Application mobile de la boutique** : reprendra la lecture de l'état et le refus des commandes à sa reprise ; d'ici là le serveur
  refuse de toute façon.
- **Mémoires Vivantes, Living Memory, Boussole** : rien à faire, la construction ne les concerne pas.

## 6. Limites connues

- Un client invité par import (`docs/import-boutique.md` § 9) ne peut pas accepter son invitation tant que la boutique est en
  construction. Inviter ses clients après l'ouverture.
- Les routes de compte communes (`APPS`) restent servies sur un site en construction : un compte né par Living Memory lit son profil,
  quitte une application, efface son compte. Elles ne montrent rien de la boutique.
- Un site EXISTANT dont le module Boutique est éteint le jour de la migration (jamais eu de boutique, essai échu, droit suspendu)
  n'a pas d'heure d'ouverture : si la boutique lui est activée ou rendue, elle est en construction jusqu'au geste « Ouvrir ». C'est
  la règle voulue pour une boutique neuve ; pour une boutique qui avait déjà servi, son administrateur la rouvre d'un geste.
- Un site créé par la commande `app:tenant:create` n'a pas de fiche : il suit la valeur de naissance (fermé en entier) jusqu'à ce
  que son administrateur l'ouvre.
- Une application (Living Memory, caisse) qui n'est pas active sur le site accepte quand même l'inscription d'un compte en
  construction (`app` nommé) : ce compte ne voit rien de la boutique.
- Après « refermer », une lecture de la boutique déjà gardée par un cache intermédiaire (60 s, plus 60 s de revalidation) peut
  encore être servie deux minutes.
- Les quatre colonnes sont hors de l'entité `Entreprise` : une migration produite par comparaison du schéma proposerait de les
  supprimer. Ne pas l'accepter.
- L'en-tête `X-Storefront-Preview` n'est pas dans la liste des en-têtes autorisés du serveur web (`docker/nginx`, hors de ce lot) :
  sans effet tant que le front appelle le serveur par son relais ; un appel direct du navigateur demanderait ce réglage.
- La liste de contrôle lit les conditions de vente dans les traductions de la fiche, et accepte un compte de paiement actif de l'un ou
  l'autre mode (essai ou réel) : elle ne regarde pas le mode de paiement du site.
- Refermer une boutique ouverte ferme aussitôt ses commandes ; les commandes déjà passées se traitent normalement dans
  l'administration. Refermer ne referme jamais le site vitrine : l'étendue `site` ne vaut qu'avant la première ouverture.
- La valeur par défaut de `storefront_state` diffère d'une base à l'autre (`open` sur les sites d'avant le lot, `construction` sur
  les sites nés après) : une migration future ne doit pas la réécrire.

## Tenir cette fiche à jour

Toute route publique nouvelle se classe dans `StorefrontGateSubscriber` : laissée sans classement, elle est une route de la boutique,
fermée en construction ; sinon `KEPT`, `APPS` ou `SHOWCASE`, et, pour une écriture publique, `SHOP_ORDERS` ou `SHOWCASE_ORDERS`.
L'essai de classement le rappelle : une route de plus dans `KEPT` ou dans les routes de compte de `APPS` le fait échouer.
