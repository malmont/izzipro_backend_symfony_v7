# Architecture du backend (app_v2)

Ce qui est commun à tout le backend. Chaque module a sa fiche (voir « Modules »), les routes sont dans
`docs/endpoints.md`. À tenir à jour à chaque changement structurel.

## En bref

- **Symfony 6.4, PHP 8.3, PostgreSQL 15, Redis 7**, dans Docker (`docker-compose.yml`). Une seule application sert
  **plusieurs clients (tenants)**, chacun avec **sa propre base**.
- Frontend : autre dépôt (Next.js, `Izzipro_next`) ; il appelle `/api/*` avec l'en-tête `X-Tenant-Host`. Sa doc :
  `AGENTS.md` et `docs/architecture.md` de ce dépôt-là.
- ⚠️ **Le code est monté en direct dans les conteneurs** (`.:/var/www`) et le serveur tourne en `APP_ENV=dev`
  (serveur de développement : ne pas passer en `prod` sans l'accord du propriétaire) :
  **toute modification de fichier est immédiatement en production**.
  Depuis le 17/10/2026, `APP_DEBUG=0` (`.env`, décision du propriétaire, lectures deux fois plus rapides) : Symfony ne
  vérifie plus la fraîcheur de son cache. Après un changement de `config/`, de route, de service ou d'entité :
  `docker exec -w /var/www symfony_app_v2 php bin/console cache:clear`. Retour arrière : retirer la ligne du `.env`.
  Les tests gardent le débogage (`APP_DEBUG=1` dans `.env.test`) ; `phpunit.xml.dist` force les valeurs de test des
  variables réelles du conteneur (code par e-mail, RevenueCat).
- ⚠️ **Configuration nginx** (`docker/nginx/default.conf`) : montée fichier par fichier dans le conteneur. La modifier
  **en place** (éditeur, `Edit`) ; `sed -i` crée un nouveau fichier que le conteneur ne voit pas. Si cela arrive :
  `docker exec -i symfony_nginx_v2 sh -c 'cat > /etc/nginx/conf.d/default.conf' < docker/nginx/default.conf`, puis
  `nginx -t` et `nginx -s reload` (un redémarrage du conteneur rétablit aussi le lien).
- **Performances des lectures publiques** (09/10/2026) : compression gzip dans nginx (`docker/nginx/default.conf` ;
  rechargement : `docker exec symfony_nginx_v2 nginx -s reload`), cache HTTP (`EventSubscriber/PublicReadCacheSubscriber` :
  ETag et 304, `public, max-age=60` (300 pour `tenant/check`), `stale-while-revalidate=600`, `Vary: X-Tenant-Host,
  Accept-Language` ; privé et revalidé quand la requête porte un jeton ; routes listées dans `ROUTES`, jamais le panier,
  les commandes ni les paiements). Une session n'est plus lue sans cookie de session (`TenantSessionIsolationListener`) :
  sinon toute réponse devenait privée. Fiche produit et listes de produits chargées collection par collection
  (`ProductRepository::loadForDetail`, `findTranslatedByCriteria`) : la requête unique faisait un produit cartésien.

## Multi-tenant

| Élément | Où |
|---|---|
| Base maître (table `tenants` : `code`, `dbname`, `custom_domain`…) | `MASTER_DATABASE_URL` → base `app_v2_db` (l'ancienne base `master` n'est plus lue par rien : ne pas y écrire) |
| Résolution du tenant d'une requête | `EventListener/TenantDoctrineSwitcherListener` : en-tête `X-Tenant-Host`, sinon `?tenant=`/`?t=` ou session (EasyAdmin), sinon l'hôte ; domaine principal du backend → `tenantdefaut`. Site demandé par l'en-tête ou `?tenant=` mais inconnu → **404 `TENANT_NOT_FOUND`** avant toute lecture (17/10/2026 ; sauf `tenant/check`, `tenant/check-mobile`, `setup/new-store` et le domaine du backend) ; sans site, base maître comme avant |
| Hôte → tenant | `TenantConnectionManager::findTenantConfigByHost` (cache Redis 1 h, hôte en minuscules) : 1) `custom_domain` (avec ou sans `www.`), 2) `code` par `codeFromHost` (règle resserrée le 17/10/2026) : code nu, `<code>.<FRONTEND_BASE_DOMAIN>`, `<code>.<…>.<BACKEND_BASE_DOMAIN>`, `<code>.localhost`, ou le domaine du frontend lui-même ; suffixe `-v2` ignoré, `-`/`_` équivalents. Un autre domaine ne désigne plus un site. `GET /api/tenant/check` suit la même règle (site inconnu : 404 « Ce site n'existe pas. », sans proposition de création depuis le 17/10/2026 ; création réservée à l'administrateur de la plateforme, formulaire `/setup/new-store` protégé par `TENANT_CREATION_SECRET_KEY`). Liste publique de ces hôtes pour le frontend : `GET /api/tenant/hosts` (`TenantPublicHostResolver::listPublicHosts`, cache 60 s, tag `tenants`) |
| Base du tenant courant | `TenantConnectionProvider` (bascule la connexion Doctrine par défaut) ; code du tenant : `getTenantCode()` |

**Règle** : toujours passer par `App\Services\TenantEntityManagerProvider` (`getEntityManager()`, `getConnection()`),
jamais par l'EntityManager par défaut ni `ManagerRegistry`. Commandes et workers : `switchTenant($dbname, $code)`.
CRUD EasyAdmin : étendre `BaseTenantCrudController`.

- **Site de test `demo`** (`demo.arkanoa-media.com`, base `db_demo`) : copie du site de l'agence, réservée aux essais
  (éditeur, assistant IA, évaluation). Seul site sur lequel on peut écrire librement ; les autres sont des clients.
### Créer un nouveau site (tenant) : la table `tenants` est la seule source

**Rien n'est codé en dur** : le listener associe l'hôte de chaque requête à sa base **uniquement** par la table
`tenants` de la base maître. Un site absent de cette table n'existe pas pour l'application, quel que soit le DNS ou
le proxy.

**La voie normale : le formulaire** `https://<backend>/setup/new-store?subdomain=<code>` (`TenantSetupController`,
protégé par `APP_SETUP_TOKEN`), qui applique la convention et remplit la table correctement :

| Colonne | Convention |
|---|---|
| `code` | **le sous-domaine du site chez le frontend**, un seul mot (`demo`, `esgboost`) : le site répond sur `<code>.<domaine de base>` (`FRONTEND_BASE_DOMAIN`) ; c'est aussi `<code>.backend-strapi.online` et `?tenant=<code>` pour EasyAdmin |
| `name` | nom affiché |
| `dbname` | `db_<code>` (base clonée de `gmasuite` par `TenantConnectionManager::createTenant`) |
| `custom_domain` | **NULL**, sauf quand le client a son propre domaine (`esgboost.ca`, `karaandb.com`) : il n'a rien à voir avec le code |
| `dbuser`, `dbpass`, `gemsuite_token` | vides (connexion par `DATABASE_URL`) |
| `is_internal_store` | `false` |

Résolution d'un hôte (`TenantConnectionManager::findTenantConfigByHost`) : 1) `custom_domain` égal à l'hôte
(avec ou sans `www.`) ; 2) sinon **la première partie de l'hôte** (`demo` dans `demo.arkanoa-media.com`) comparée à `code`
(`-` et `_` équivalents, suffixe `-v2` ignoré). Un code contenant un point ne correspond donc jamais à un hôte.
Un même `dbname` peut servir plusieurs codes ou domaines (`esgboost` / `esgboost1`).

**Création manuelle** (copie d'un site existant, comme le site de test `demo`) : reproduire exactement ce que fait le
formulaire :
1. la base : `pg_dump <source> | psql db_<code>` (sans coupure) ;
2. une ligne dans `tenants` de la base maître (`app_v2_db`, `MASTER_DATABASE_URL`) selon le tableau ci-dessus. C'est la
   seule table lue : l'ancienne base `master` (même structure) ne l'est plus par rien depuis le 30/09/2026 (les scripts
   de migration lisaient encore sa liste de bases ; corrigés). Ne pas la modifier, elle peut être supprimée ;
3. `php bin/console cache:pool:invalidate-tags tenants` (la résolution hôte → site est en cache 1 h) ;
4. vérifier : `curl -H "X-Tenant-Host: <code>.<domaine du frontend>" https://v2.backend-strapi.online/api/service-offers`
   répond avec les données de la nouvelle base.

**Hors backend** : rien pour le frontend (le DNS `*.arkanoa-media.com` est un joker vers son serveur et le certificat
est automatique : `<code>.arkanoa-media.com` répond dès que la ligne existe) ; entrée Nginx Proxy Manager
`<code>.backend-strapi.online` seulement pour ouvrir EasyAdmin à sa propre adresse (le DNS `*.backend-strapi.online`
est un joker) : demander le certificat **pour ce nom exact** dans l'onglet SSL de l'hôte (un certificat émis pour un
autre nom, par exemple après un renommage de l'hôte, donne « non sécurisé »). Aucun changement de code ni de configuration nginx, **sauf** pour un nouveau domaine racine appelé
directement par un navigateur : la liste CORS de `docker/nginx/default.conf` est la seule liste de domaines codée en dur
(inutile pour les appels qui passent par le relais du frontend).

Ce que contient `gmasuite` devient la valeur par défaut des nouveaux clients (`TenantConnectionManager::createTenant`),
dont le contenu d'exemple de la landing page et de la boutique, marqué `is_demo` à la création : `docs/contenu-exemple.md`.
- **Schéma sur toutes les bases** : les deux, toujours :
  1. une migration Doctrine dans `migrations/`, **idempotente** (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT
     EXISTS`) : c'est la trace versionnée, rejouable par `app:tenant:migrate-all` (un tenant : `app:tenant:migrate-db`,
     une seule migration : `app:tenant:execute-migration`) ;
  2. un script `scripts/migrate_all_v2_<sujet>.sh`, qui applique le même SQL **tout de suite** à toutes les bases
     (le code étant en production dès son enregistrement, le schéma doit l'être avant ou en même temps). Partir d'un
     script existant : il lit la liste des bases dans la table `tenants`, ajoute `app_v2_db` et `db_mv_test_booktypes`
     (modèle des tests : sans elle, les tests échouent), et affiche ✅/❌ par base. Scripts existants (hors dépôt,
     `scripts/` est ignoré par git) : `migrate_all_v2_memoires.sh`, `…_book_types.sh`, `…_book_payment_fields.sh`,
     `…_book_print_order.sh`, `…_reservation.sh`, `…_reservation_biographer.sh`, `…_payments_unique_stripe.sh`,
     `…_shared_media.sh`, `…_video_fields.sh`, `…_landing_ai.sh`, `…_landing_site_models.sh`, `…_content_audit.sh`,
     `…_contact_fields.sh`, `…_boutique_settings.sh`, `…_product_modes.sh`, `migrate_v2_reservation_steps.sh`.
  - Les scripts lisent la liste des bases dans `app_v2_db` (comme l'application) depuis le 30/09/2026.
  - La base modèle `gmasuite` est dans `tenants` : un nouveau tenant hérite donc du schéma à jour.
- Tables de plateforme (pas dans les bases tenant) : dans `app_v2_db`, via `TenantConnectionManager::getPdoMaster()`
  (ex. `landing_config_sync`, `tenant_app_keys` : `scripts/migrate_master_app_keys.sh`). L'hôte public d'un site
  (`custom_domain`, sinon `<code>.FRONTEND_BASE_DOMAIN`) est calculé par `Services/TenantPublicHostResolver`.

## Authentification et sécurité

| Sujet | Où |
|---|---|
| Pare-feux | `config/packages/security.yaml` : `api` (`^/api` sauf routes publiques listées, JWT sans état), `main` (reste : formulaire Twig, session) |
| Utilisateurs | par tenant : `Security/TenantUserProvider` (table des utilisateurs de la base du tenant) |
| Connexion web | `POST /api/login` → cookies `auth_token_<tenant>` (JWT) et `XSRF-TOKEN_<tenant>` ; `POST /api/token/refresh` ; `POST /api/logout` (`Controller/Account/SecurityController`) |
| Plateformes de connexion (`platform` du corps de `/api/login` et `/api/otp-verify`, `Services/AuthenticationService`) | `web` (défaut du login : cookies + `csrf_token`, exige `ROLE_USER_INTERNET`), `mobile` (défaut de l'OTP : jetons dans le corps, `ROLE_USER_INTERNET`), `pos` (caisse, `ROLE_USER_POS`), `admin` (application de gestion, `ROLE_ADMIN`, 15/10/2026). Comparée après `trim` et minuscules ; **toute autre valeur → 400 `PLATFORM_INVALID`** (journalisée) : avant le 15/10/2026 une valeur inconnue sautait tout contrôle de rôle |
| Session des applications natives | `POST /api/token/refresh` avec `{refresh_token}` dans le corps renvoie aussi le nouveau JWT dans le corps (`token`, 15/10/2026), en plus des cookies posés comme avant ; le refresh token n'est pas renouvelé (7 jours) |
| Clé d'activation des applications mobiles (15/10/2026) | Quatre applications : `pos` (caisse Flutter), `mobile` (e-commerce Flutter), `admin` (gestion .NET MAUI), `livingmemory` (Mémoires Vivantes mobile). **Activation par site dans la fiche Entreprise** (EasyAdmin, panneau « Applications mobiles », indicateurs `isPosActive`, `isMobileShopActive`, `isManagementAppActive`, `isLivingMemoryActive`, exposés par `/api/entreprise/{id}` ; colonnes : `scripts/migrate_all_v2_app_activation.sh`) : activer génère la première clé et l'envoie au contact du site (clé en clair + **code QR** incorporé et joint en PNG, `endroid/qr-code` + GD, contenu du QR = la clé) ; désactiver révoque les clés de l'application (`Services/AppKeyService/AppActivationService`, appelé par `EntrepriseCrudController::updateEntity`). Une clé = un site + une application, jamais un utilisateur ; **aucune limite d'appareils** : chaque activation incrémente `useCount` et remplace `lastDevice` (le `deviceName` envoyé), le `label` rendu est celui de la clé. L'application grand public **Living Memory** (`livingmemory`, Flutter, sur les stores) embarque ainsi une seule clé par site, la même sur tous les téléphones ; la clé n'est pas un secret et ne donne aucun droit (seulement les paramètres publics du site), les clés ne sont pas liées à l'identifiant de l'application. Format `IZ-<POS\|MOB\|ADM\|LMV>-XXXX-XXXX-XXXX`, empreinte SHA-256 dans la table de plateforme `tenant_app_keys` (base maître, `scripts/migrate_master_app_keys.sh`), clé en clair montrée une fois et envoyée au contact du site (fiche entreprise). Gestion par l'administrateur du site : écran EasyAdmin « Clés des applications » (`Controller/Admin/AppKeyAdminController`) ou `GET`/`POST /api/app-keys`, `DELETE /api/app-keys/{id}` (ROLE_ADMIN). Une clé `pos` peut porter un poste de caisse (`cashRegisterId`, nom copié sur la clé ; `PUT /api/app-keys/{id}` pour le changer). Activation : `POST /api/app/activate {key, app?, deviceName?}` (public, hors pare-feu JWT, 20 essais par IP et 15 min) → `{tenant: {code, name, host}, app, label, expiresAt, cashRegister: {id, name} | null}` ; 400 `APP_KEY_MISSING`, 404 `APP_KEY_INVALID` (inconnue, révoquée, expirée), 403 `APP_KEY_WRONG_APP`. Code : `Services/AppKeyService/`, `Repository/TenantAppKeyRepository`, `UseCase/AppKeyUseCase/`, `Controller/AppKeyController/`. L'utilisateur se connecte ensuite normalement (`/api/login`, `platform` : `pos`, `mobile`, `admin` ; Living Memory utilise `mobile`, pas de valeur propre) avec `X-Tenant-Host` = `tenant.host` (domaine propre du site s'il en a un, sinon `<code>.<FRONTEND_BASE_DOMAIN>`) |
| Vente d'une carte-cadeau au terminal Stripe (01/11/2026, complément F9 / C4) | `POST /api/pos/terminal/payments` accepte `giftCardAmount` à la place de `items` ; destination `pos_terminal_payment.purpose` (`sale` | `gift_card`, métadonnée Stripe `pos_purpose`) vérifiée au rattachement (409 `TERMINAL_PAYMENT_PURPOSE_MISMATCH`) ; rapports X/Z : `cardTerminal` compte les ventes de cartes (détail `giftCards`), jamais les ventes ; export F14 inchangé (cartes à encaisser / cartes-cadeaux en circulation) ; kit F13/F16 : même entrée. Migration `Version20261101110000`. Contrat : `docs/caisse.md`. |
| Coupons de fabricant en caisse (01/11/2026, F17) | Bon remboursable par un tiers traité comme un **moyen de paiement** (part `type: 6`, modèle F9 : `order.manufacturer_coupon_applied`, table `pos_manufacturer_coupon`), taxes sur le prix plein (LTA art. 181(2), Revenu Québec) ; lecture des codes-barres GS1 DataBar 8110 et UPC-A système 5 (`Services/PosCouponService/CouponBarcodeParser`, `POST /api/pos/coupons/parse`, `amountReliable`) ; doublon de série (409 `COUPON_ALREADY_USED`), expiration (422 `COUPON_EXPIRED`), montant ≤ ligne, validation C3 `manufacturer_coupon` au-delà de 20 $ par vente (réglable) ; retours : part coupon jamais remboursée, coupon `voided` ou `returned_after_claim` ; réclamation par fabricant et période (`/api/pos/coupons/claims`, CSV, marquage par lot, page EasyAdmin `/admin/pos/coupons`) ; rapports X/Z et compte `coupon_receivable` (F14) ; hors ligne refusé. Contrat : `docs/caisse.md`. |
| Kit Stripe Terminal dans l'application de caisse (31/10/2026, F13 / F16) | Tap to Pay (iPhone, Android dont Sunmi T3 / D3 MINI) et lecteur Bluetooth WisePad 3 : mode du terminal par poste (`cash_register.terminal_mode`, déduit de l'existant), autorisations par site (défaut non), `POST /api/pos/terminal/connection-token` (compte connecté, emplacement du site, limiteur `pos_connection_token_terminal`, journal sans secret), routes C4 réutilisées avec un canal `pos_terminal_payment.channel` (`sdk` : `clientSecret` rendu, confirmation par le kit, serveur relit Stripe ; `server` inchangé, jamais de secret), remboursement Interac présenté par le kit puis `…/refund/confirm` (expiration réglable, reprise par la réconciliation, journal REFUND_PENDING / CONFIRMED / EXPIRED). Contrat : `docs/caisse.md`. |
| Sécurité réservations et newsletter (27/10/2026) | `POST /api/reservations/{id}/confirm` : connexion obligatoire (`access_control`) et, dans `ReservationApiController::confirm`, administrateur ou biographe de la réservation (401 / 403 / 404), réponse sans `client_email` ; avant, n'importe qui confirmait n'importe quel identifiant. `GET /api/newsletter` : `ROLE_ADMIN` (lisible par tout compte connecté avant). Messages d'erreur génériques au visiteur (réservation, `contact/submit`, financement), détail dans le journal. Garde-fou : exemption de `AnonymousWriteAccessTest` limitée à `POST /api/reservations` ; test `Security/ReservationConfirmAccessTest`. |
| Sécurité web S7 (31/10/2026) | `POST /api/payment` : propriété du PaymentIntent (session de paiement ou commande de l'utilisateur, invité avec `guestToken`, administrateur), sinon 404 `PAYMENT_NOT_FOUND` (`Services/PaymentService/PaymentOwnershipService`) ; `PATCH /api/profile` : changement réel d'adresse courriel confirmé par `currentPassword` ou, pour les comptes OTP, par un code envoyé à l'ancienne adresse (`Services/AccountService/EmailChangeGuard`, limiteur `profile_email_change_account`, courriel d'information à l'ancienne adresse, journal sécurité). Contrat et changements attendus des fronts : `docs/boutique.md`. |
| Conservation des données de vente, rôles de caisse, Loi 96 (30/10/2026, A1 / S2 / A4) | Durées par pays (`Services/RetentionService/RetentionPolicy`, Canada 6 ans après la fin de l'année, France et DROM 10, plancher légal, allongeable par site dans la fiche Entreprise ; **aucune purge automatique**) ; garde Doctrine `EventSubscriber/RetentionGuardSubscriber` (suppression des données de vente et de leurs parents refusée avec message clair, 409 `RETENTION_LOCKED` ; modifications hors exceptions journalisées `RETENTION_WRITE_OBSERVED` en mode `observe` (défaut), refusées en mode `block`) ; instantanés `order_items.product_name/variant_label/product_code/barcode` remplis à la vente (caisse et web), rétro-remplis ; archive annuelle ZIP (JSON + PDF de synthèse + SHA256SUMS) en flux, `GET /api/pos/archive/{year}` et `/admin/pos/archive`, journal `ARCHIVE_EXPORTED` ; rattachement clé POS → poste (`CashRegisterResolver`, 403 `CASH_REGISTER_FORBIDDEN` pour le caissier, retours et réimpressions libres) ; cartes-cadeaux : 404 uniforme et limite de codes refusés (429 `GIFT_CARD_LOOKUP_LIMIT`), validations C3 limitées par terminal ; audit des 153 routes authentifiées atteignables par un caissier (`docs/caisse.md`, écarts corrigés : notes de frais (administrateur ou propriétaire de la collection), étiquettes, pages HTML d'adresses, `/api/order/create` par un caissier, révocation de lien) ; documents de caisse en français (Loi 96). Sauvegardes `backup-daily.timer` (04:00 UTC, 14 jours) = restauration, pas conservation légale. Contrat : `docs/caisse.md`. |
| Statistiques par caissier et export comptable (29/10/2026, F14) | `GET /api/pos/statistics/cashiers` (vendeur réel, fuseau du magasin `Services/CaisseService/StoreClock`, caissier limité à lui-même, journal CASHIER_STATS_VIEWED), `GET /api/pos/exports/accounting[/summary.csv|/entries.csv]` et page EasyAdmin `/admin/pos/exports` (rapports Z rattachés à la date d'ouverture, journal EXPORT_GENERATED) ; service commun `Services/AccountingService/` (ChartOfAccounts par site, JournalEntryBuilder équilibré, source pos aujourd'hui, web ensuite) ; vecteur de chaînage `chainVectors` dans `config/pos/offline-cases.json`. Contrat : `docs/caisse.md`. |
| Fiche client et fidélité en caisse (28/10/2026, F8) | `pos_customer` (fiche distincte du compte web, rattachable avec consentement `account_link`, rien écrit sur `user`), `pos_customer_consent`, `pos_loyalty_transaction`, `order.customer_id` ; routes `/api/pos/customers` (`Controller/PosController/PosCustomerController`, `Services/PosCustomerService/`) ; recherche web sur courriel ou téléphone exact seulement, masquage caissier, journal des recherches et consultations, effacement = anonymisation ; points sur le net hors part carte-cadeau, rachat en remise F1 sans plafond caissier ; hors ligne : client connu mis en file. Contrat : `docs/caisse.md`. |
| Cartes-cadeaux et avoirs en caisse (27/10/2026, F9) | `pos_gift_card` (empreinte du code seule, solde = passif) et `pos_gift_card_transaction` ; routes `/api/pos/gift-cards` (`Controller/PosController/PosGiftCardController`, `Services/PosGiftCardService/`) ; vente = commande de type 3 « CarteCadeau » (hors chiffre d'affaires, transaction de caisse 9/10, ticket `gift_card`) ; utilisation par types 4/5 du corps (aucune ligne dans la table des moyens de paiement : `order.gift_card_applied`) ; avoir = méthode de retour `store_credit` ; validations C3 `gift_card_cancel/adjust/replace` ; limiteurs `pos_gift_card_lookup_*` ; statistiques de revenu sur les types 1 et 2. Contrat : `docs/caisse.md`. |
| Article libre et prix manuel en caisse (26/10/2026, P2-a / F7) | `items[].custom` (ligne de commande sans variante : `order_items.custom_label`, `taxable`, aucun stock) et `items[].priceOverride` (`catalog_unit_price`, motif) dans le devis de caisse et la vente (`Services/PosLineService/`, `CartQuoteCalculator`, `TaxEngine` lignes non taxables) ; validation groupée C3 (`pos_approval.actions`, `PosApprovalService::requireAll`) ; journal `PRICE_CHANGED` ; rapports X/Z ; hors ligne (règle v1 étendue, 3 cas) ; retour libre soumis à `returns.withoutReceipt` ; `PaymentsRepository::getDailyPaymentsForCurrentWeek` par jour. Réglages par site (EasyAdmin). Contrat : `docs/caisse.md`. |
| Mode hors ligne du POS (25/10/2026, C5 partie 2) | `GET /api/pos/offline-pack` (ETag, instantané conservé par version dans `pos_offline_pack`), `GET /api/pos/offline-cases` (règle pos-offline-v1 et jeu de cas partagé `config/pos/offline-cases.json`), `POST /api/pos/offline-sales` (lot, clé d'idempotence par terminal `pos_offline_sale`, recalcul au pack déclaré, acceptation forcée C3 `offline_force`, `pos_offline_sync`) ; `Services/PosOfflineService/` ; colonnes additives `product_variant.barcode`, `pos_receipt.provisional_number`, `order.synced_at` ; contrat dans `docs/caisse.md` |
| Ticket numérique (24/10/2026, F3) | Bloc ticket complet (lignes, caissier, cartes, `receiptUrl`), réimpression `GET /api/pos/receipts`, reçu par courriel `POST /api/pos/receipts/email` (SMTP du site, PDF dompdf, adresse jamais conservée), page publique du reçu `GET /api/r/{jeton}` hors pare-feu JWT (jeton HMAC, expiration, révocation par version, `noindex`, `no-store`), servie sous `/api/` pour être routée vers le backend sur tout hôte de site ; `Services/PosAuditService/PosReceiptLinkService`, `PosReceiptMailer` ; contrat dans `docs/caisse.md` |
| Remises de caisse (24/10/2026, F1) | `POST /api/pos/quote` (`Controller/PosController/PosQuoteController`, `Services/PosDiscountService/`) ; remises de ligne et de ticket calculées par le serveur, taxes sur le net, honorées seulement sur les chemins de caisse (`CartQuoteInputDto::fromArray(..., allowDiscounts)`), colonnes additives `order_items.discounts/discount_amount`, `order.discount_*` ; plafonds par rôle, validation C3 action `discount` ; contrat dans `docs/caisse.md` |
| Retours partiels et échanges en caisse (24/10/2026, F2) | Routes `/api/pos/returns`, `/api/pos/returns/lookup`, `/api/pos/exchanges` (`Controller/PosController/PosReturnController`, `Services/PosReturnService/`) ; commande de retour liée à la vente (`order.returned_from_id`, `order_items.returned_from_item_id`, colonnes nullables), `pos_return`, crédit d'échange `order.credit_applied` ; code-barres `IZ-T-<poste>-<numéro>` ; remboursement carte d'abord, Stripe partiel ou Interac en deux temps ; validation C3 action `return` ; contrat dans `docs/caisse.md` |
| Réglages de caisse par site et garde du terminal (23/10/2026, R1) | Réglages dans la fiche Entreprise lus par `GET /api/pos/settings` (PIN, fond, moyens de paiement, ticket, paniers, alerte espèces) ; commission en ligne réglable (`stripe_config.online_fee_bp`, `StripeService::onlineFee`, troncature identique) ; `EventSubscriber/PosTerminalGuardSubscriber` : 403 `TERMINAL_REVOKED` pour l'en-tête d'une clé révoquée, 403 `APP_KEY_REVOKED` à la réactivation, `TERMINAL_REQUIRED` si le site l'exige (désactivé par défaut) ; contrat dans `docs/caisse.md` |
| Terminal de paiement Stripe de la caisse (23/10/2026, C4) | Intégration pilotée par le serveur sur le **compte connecté du site** : routes `/api/pos/terminal*` (`Controller/PosController/PosTerminalController`, `Services/PosTerminalService/`), PaymentIntent `card_present` + `interac_present` (`manual_preferred` : carte autorisée puis capturée dans la transaction de la vente, Interac débité aussitôt), `metadata.pos_terminal = "1"` ignoré par le webhook avant la branche boutique ; clés d'idempotence Stripe dérivées de la clé POS et du PaymentIntent ; réglages par site dans EasyAdmin ; cron `app:pos:terminal-reconcile` ; contrat dans `docs/caisse.md` |
| Idempotence des écritures de caisse (22/10/2026, C5) | En-tête **`Idempotency-Key`** (UUID, accepté par CORS) sur les neuf écritures de caisse (vente, remboursement, session, mouvements, tiroir) : rejeu de la même réponse avec `Idempotent-Replayed: true`, 409 + `Retry-After` si en cours, 422 si réutilisée ; clé marquée réussie dans la transaction de l'écriture, réponse reconstituée si perdue ; `EventSubscriber/PosIdempotencySubscriber`, `Services/PosAuditService/PosIdempotencyService`, table `pos_idempotency_key` (7 jours) ; contrat dans `docs/caisse.md` |
| PIN et validations de caisse (20/10/2026, C3) | `/api/pos/pin-login` et `/api/pos/users` sont des routes de connexion **hors pare-feu JWT** (motif `firewalls.api`), exigeant `X-Pos-Terminal` ; validation d'un responsable rejouée par l'en-tête **`X-Pos-Approval`** (accepté par CORS) ; PIN haché avec le secret du serveur (`PosPinService`) ; contrat dans `docs/caisse.md` |
| Terminal de caisse et journal (19/10/2026, C2) | Une clé `pos` activée rend `terminal {id, token}` (`Services/PosAuditService/PosTerminalService`, jeton HMAC sur le secret de l'application) ; le POS envoie `X-Pos-Terminal` (en-tête accepté par CORS dans `docker/nginx/default.conf`). Journal d'audit inaltérable de la caisse : `Services/PosAuditService/PosAuditService`, table `pos_audit_log` (déclencheur PostgreSQL) ; contrat dans `docs/caisse.md` |
| Choix du site par les applications mobiles (repli, sans clé) | `GET /api/tenant/check-mobile` (public, `TenantController::checkMobile`) : `X-Tenant-Host` = saisie nettoyée (code `demo` ou domaine) → `{exists, tenant: {code, name, host}}`, `host` = hôte complet à envoyer ensuite (domaine propre, sinon `<code>.FRONTEND_BASE_DOMAIN`) ; 404 `TENANT_NOT_FOUND`, 400 `TENANT_HOST_MISSING`. `GET /api/tenant/check` reste la route du frontend web (inchangée) |
| Profils des applications (20/10/2026) | Nom, logo, icône, accroche, couleur principale, coordonnées, liens (assistance, politique de confidentialité), adresse et textes (à propos, confidentialité, conditions, mentions) **propres à une application du site**, table `app_profile` (une ligne au plus par application, **vide par défaut** ; `Entity/AppProfile`, `scripts/migrate_all_v2_app_profile.sh`, migration `Version20261020110000`). Une application est désignée par un **code fixe** embarqué dans l'application, jamais par son nom : `livingmemory`, `memoires` (web Mémoires Vivantes), `boussole`, `pos`, `mobile`, `admin` (liste fermée `AppProfile::APPS` ; la landing page et la boutique n'en ont pas et utilisent la fiche entreprise). Le site est celui de `X-Tenant-Host` : chaque site a ses propres profils. Traductions (`Entity/AppProfileTranslation`, table `app_profile_translation`, migration `Version20261020120000`, sur le modèle de `EntrepriseTranslation`) : nom affiché, accroche, à propos et textes légaux par langue (`fr`, `en`), choisis selon `?locale=` (sinon la traduction française, sinon le champ du profil, sinon l'entreprise traduite) ; la réponse indique `locale` et `translation` (langue retenue ou null). `GET /api/app-profile/{app}` (public, `AppProfileController`, `Services/AppProfileService`) rend le profil **déjà fusionné** : un champ vide prend la valeur de la fiche entreprise (traduite selon `?locale=`), l'adresse est prise en bloc (celle de l'application si elle en a une, sinon celle de l'entreprise) → `{app, appLabel, hasProfile, name, tagline, logo, icon, primaryColor, email, phone, website, supportUrl, privacyPolicyUrl, address: {street1, street2, city, zip, province, country, oneLine}, apropos, privacyPolicy, conditionOfUse, legalNotice, sources: {<champ>: "app" | "entreprise" | null}, entreprise: {id, name}, updatedAt}` ; 404 `APP_UNKNOWN` (avec la liste `apps`) pour un code inconnu. `POST /api/app/activate` rend aussi `profile` (même forme, null si le site ne peut être lu). Réglage : EasyAdmin, menu Entreprise, « Profils des applications » (`Controller/Admin/AppProfileCrudController`, une fiche par code, doublon refusé, collection « Contenus traduits » comme la fiche Entreprise, logos dans le dossier de ceux de l'entreprise). Tests : `tests/Functional/AppProfile/AppProfileTest.php` |
| JWT lu depuis le cookie | `EventListener/JWTFromCookieListener` (si pas d'en-tête `Authorization`) ; durées : `WEB_ACCESS_TOKEN_TTL`, `WEB_SESSION_IDLE_TTL` ; le jeton porte `tenant_code` et n'est valable que sur ce site ; un jeton sans `tenant_code` (émis hors de tout site) est refusé sur un site (`JWTDecodedListener`) |
| CSRF | `EventListener/CsrfValidationListener` : sur `/api/*` hors GET, si le cookie d'auth du tenant est présent, en-tête `X-XSRF-TOKEN` = cookie `XSRF-TOKEN_<tenant>` |
| Rôles | `ROLE_ADMIN` (administrateur du site), `ROLE_SUPER_ADMIN` (propriétaire de la plateforme, testé explicitement : pas de `role_hierarchy` ; ni attribuable ni retirable par un admin de site : `Security/RoleAssignmentPolicy`, appliquée par l'écran EasyAdmin des utilisateurs et par `/api/memoires/admin/users` (compte super admin : ni modifiable ni supprimable par un admin de site) ; sinon en SQL. Détenu par le seul compte du propriétaire, dans toutes les bases, `gmasuite` comprise, donc aussi dans chaque nouveau site), `ROLE_USER_INTERNET` (nécessaire à la connexion web), `ROLE_USER_POS` (caissier : caisse, postes en lecture, vente `create-multi-payment`, commandes, paiements et statistiques de la source caisse seulement, voir `docs/caisse.md`), `ROLE_POS_MANAGER` (responsable de caisse, 16/10/2026 : en plus, écriture des postes et remboursement des ventes POS d'autrui ; depuis les lots F14 à S2 : exports comptables, archive annuelle, statistiques de tous les caissiers, fusion/effacement/export de fiches clients, révocation de liens de reçu, actions C3 sans validation, tous les postes même avec un terminal rattaché ; aucun accès au back-office ni aux autres modules), rôles Boussole (`ROLE_COMPANY`, `ROLE_CONSULTANT`) |
| Limites de débit | `config/packages/rate_limiter.yaml` (connexion, OTP, mot de passe, formulaires publics par IP, assistant IA par tenant) ; `EventSubscriber/PublicEndpointRateLimitSubscriber`. Formulaires publics (site + IP) : 10 envois **acceptés** (2xx) par 10 minutes glissantes, comptés à la réponse (un refus 422 ne compte pas), et 60 tentatives par 10 minutes ; un 429 ne rallonge pas le blocage |

**`access_control` : la première règle qui correspond l'emporte.** L'ordre compte : une règle précise (ex.
`^/api/landingpage-ai`) doit précéder la règle publique générale qui contient `landingpage`. Le rôle exigé par chaque
route est calculé dans `docs/endpoints.md`. Garde-fou : `tests/Functional/Security/AnonymousWriteAccessTest.php`
refuse toute route d'écriture sans rôle qui n'est pas une exception justifiée.

### Ressources API Platform (`#[ApiResource]`, audit S8 du 26/10/2026)

Une collection API Platform **sans groupes de sérialisation** imbrique toutes les relations des entités qui ne sont pas des ressources
(variantes, réservations, avis, commandes, utilisateurs…), et ces relations bouclent : une seule requête peut occuper un processus
PHP-FPM plusieurs minutes et plusieurs gigaoctets (incident du 26/10/2026 sur `GET /api/products`). Règle : **toute ressource exposée
a un groupe de lecture borné (champs simples, aucune relation imbriquée non bornée) et une pagination plafonnée** ; la lecture
publique est réservée à ce dont les fronts ont besoin. Les fronts lisent des routes dédiées (DTO à champs explicites).

| Ressource | Opérations | Lecture | Groupes de lecture | Pagination | Remarque |
|---|---|---|---|---|---|
| `Product` | liste, fiche (`/api/products/{id}.{format}`), écritures | **administrateur** (401 / 403 avant toute requête) | `product:list` : champs simples | 30, client jusqu'à 100 | S8 ; avant : publique, relations sans limite. La fiche `/api/products/{id}` (sans suffixe) est la route dédiée `get_product_detail` (DTO), publique, inchangée. La route dédiée `get_all_products` (`GET /api/products`, `ProductController::getAllProducts`) est masquée par celle d'API Platform, enregistrée avant elle : elle ne répond jamais (`docs/endpoints.md` l'affiche quand même) |
| `Categories` | liste, fiche, écritures (administrateur) | **administrateur** | `category:list` : champs simples, sans produits ni packs | 30, client jusqu'à 100 | S8 ; avant : tout client connecté, produits imbriqués. La boutique lit `/api/category` (route dédiée, inchangée) |
| `Vehicle` | liste, fiche, écritures (administrateur) | publique | `vehicle:read` : champs simples, sans produit ni traductions | 24, plafond 50 | S8 ; aucune donnée en base aujourd'hui |
| `VehicleProduct` | liste, fiche, écritures (administrateur) | publique (site : `?page=1&pageSize=50`, `pageSize` ignoré, 24 par page) | `vehicle_product:read` | 24, plafond 50 | bornée : champs simples du véhicule et du produit, `bookingConfiguration` (une par produit) ; la liste des réservations n'est pas exposée (vérifié par test) |
| `Commande` | toutes par défaut | administrateur | aucun | 30 | à traiter plus tard (groupes) ; aucune donnée en base |
| `Collections` | — | — | — | — | non exposée (attribut en commentaire) |

Hors API Platform, à surveiller : `GET /api/memoires/admin/users` (administrateur) rend la liste complète des utilisateurs sans
pagination ; proposition : `?page=` et `?limit=` (défaut 50, plafond 200) avec le total.

Contrôles en test : `tests/Functional/Boutique/ApiPlatformBoundedReadsTest.php` (401 / 403 sans requête sur les produits, champs
bornés, pagination, nombre de requêtes SQL constant des routes des fronts quel que soit le nombre de commandes ; enregistreur de requêtes
`tests/Fake/SqlRecorderMiddleware.php`), `tests/Functional/Doctrine/InverseSideMappingTest.php` (tous les côtés inverses des relations
pointent vers leur entité : `doctrine:schema:validate` ne le vérifie pas).

## Organisation du code et conventions

Toute nouvelle fonctionnalité suit les couches **Repository > Service > UseCase > Controller > DTO** (+ écran
EasyAdmin si utile). S'appuyer sur l'existant : chercher d'abord un service ou un cas d'usage à réutiliser.

| Couche | Dossier | Rôle | Exemple |
|---|---|---|---|
| Entité | `src/Entity/` | mapping Doctrine, `#[ORM\Entity(repositoryClass: …)]` | `AiUsage`, `AiJob` |
| Dépôt | `src/Repository/` | requêtes (QueryBuilder), **étend `Doctrine\ORM\EntityRepository`** | `AiUsageRepository` |
| Service | `src/Services/<Domaine>Service/` | logique métier réutilisable, sans HTTP | `LandingAiQuotaService` |
| Cas d'usage | `src/UseCase/<Domaine>UseCase/` | un scénario : contrôles, orchestration des services, erreurs métier | `ComposeLandingSectionUseCase` |
| Contrôleur | `src/Controller/<Nom>Controller/` | HTTP seulement : lit la requête, construit le DTO, appelle le cas d'usage, renvoie la réponse | `LandingAiController` |
| DTO | `src/Dto/` | entrée (`fromRequestBody()`, `validate()` → liste `{ path, message }`) et sortie (`…OutputDto`) | `LandingAiComposeInputDto` |
| Administration | `src/Controller/Admin/` | EasyAdmin (`/admin`, menu dans `DashboardController`) | `AiUsageCrudController` |

Modules autonomes (mêmes couches dans leur dossier) : `src/MemoiresVivantes/`, `src/ESG/` (Boussole ESG).
Code plus ancien, à ne pas prendre comme modèle : `src/ApiResource/` (API Platform), contrôleurs Twig de l'ancienne
boutique (`templates/`), dépôts qui étendent `ServiceEntityRepository` (45, surtout les traductions).

### Règles multi-tenant dans le code

- **Dépôts** : étendre `Doctrine\ORM\EntityRepository` (136 dépôts le font), **pas** `ServiceEntityRepository` : ce
  dernier est un service lié à l'EntityManager par défaut via `ManagerRegistry`. On obtient un dépôt par l'EntityManager
  du tenant, jamais par injection :
  ```php
  $repo = $this->emProvider->getEntityManager()->getRepository(AiUsage::class); // TenantEntityManagerProvider
  ```
- **EntityManager, connexion** : `TenantEntityManagerProvider::getEntityManager()` / `getConnection()` ; code du
  tenant : `TenantConnectionProvider::getTenantCode()`. Jamais `EntityManagerInterface`, `ManagerRegistry` ni
  `$this->getDoctrine()` injectés directement.
- **Commandes et handlers Messenger** : pas de requête HTTP, donc pas de tenant : le message porte le code du tenant ;
  le handler le cherche dans la base maître (`TenantConnectionManager::findTenantByCode()`), puis appelle
  `TenantEntityManagerProvider::switchTenant($dbname, $code)` (voir `MessageHandler/LandingAiJobHandler.php`). Même
  chose dans une commande qui parcourt les tenants (voir `ReglableCompositionScanner`, qui revient ensuite au tenant
  de départ).
- **EasyAdmin** : tout CRUD d'une entité de tenant **étend `BaseTenantCrudController`** (il fait passer liste,
  création, modification et suppression par l'EntityManager du tenant). Actions personnalisées qui modifient des
  données : `CsrfProtectedActionTrait`. Ajouter l'entrée de menu dans `DashboardController`.
- **Tables de plateforme** (communes à tous les tenants) : base maître par `TenantConnectionManager::getPdoMaster()`,
  dans un dépôt dédié (ex. `LandingConfigSyncRepository`).

### Autres conventions

- **Sécurité d'une route** : règle `access_control` (ordre !), et dans le code : voter (`denyAccessUnlessGranted`),
  `#[IsGranted]` ou garde dédiée (ex. `MemoiresVivantes/Security/BookAccessGuard`). Toute route d'écriture exige un rôle.
- **Configuration** : `#[Autowire('%env(default::MA_VARIABLE)%')]` dans le constructeur (valeur vide par défaut, jamais
  de secret en dur) ; paramètres liés pour tous les services dans `config/services.yaml` (`bind` : `$projectDir`,
  `$storagePublicUrl`, clés Lulu…) ; paramètres propres aux tests : `when@test` et `config/services_test.yaml`.
- **Erreurs d'API** : `{ error, message, errors? }` (`errors` : liste `{ path, message }`) ; 422 pour un contenu refusé.
- **JSON libre** (compositions, réglages) : décoder en objets (`json_decode($json, false)`) et réencoder avec
  `JSON_PRESERVE_ZERO_FRACTION`, sinon `{}` devient `[]` et `1.0` devient `1`.
- **Langue** : textes et commentaires en français ; noms de classes et de méthodes en anglais.

## Tâches de fond (Messenger)

Une file et un worker par projet, dans la base Redis 1 (`config/packages/messenger.yaml`, `docker-compose.yml`) :

| Transport (file Redis) | Conteneur | Messages |
|---|---|---|
| `async` (`messages`) | `symfony_messenger_worker_v2` | chapitres Mémoires Vivantes |
| `esg` (`messages_esg`) | `symfony_messenger_worker_esg_v2` | rapports Boussole ESG |
| `landing_ai` (`messages_landing_ai`, sans relance) | `symfony_messenger_worker_landing_v2` | tâches de l'assistant IA |
| `media` (`messages_media`, sans relance) | `symfony_messenger_worker_media_v2` | vidéos de la médiathèque préparées pour le défilement |
| `email` (`messages_email`, relances 30 s / 2 min / 8 min) | `symfony_messenger_worker_email_v2` | courriels de tous les sites (09/10/2026) |
| `failed` (`messages_failed`) | — | messages en échec (inspection) |

Le worker `media` a sa propre image (`docker/worker-media/Dockerfile` : l'image de l'application plus **ffmpeg**) : c'est
le seul conteneur qui encode des vidéos, le site n'encode jamais rien. Après une reconstruction de l'image de
l'application : `docker compose build messenger_worker_media && docker compose up -d --no-deps messenger_worker_media`.

**Courriels** (worker `email`, 09/10/2026) : tout envoi passe par `TenantMailerFactory` (`createMailer($config)` pour le
serveur SMTP du site, `createPlatformMailer()` pour celui de la plateforme, `MAILER_DSN`), qui rend un `QueuedMailer` : le
courriel est composé dans la requête (gabarit Twig rendu, langue et domaine du site), copié sans son contexte de gabarit,
puis mis en file (`SendTenantEmailMessage` : e-mail, enveloppe, code et base du site, identifiant de la configuration
d'envoi). **Aucun identifiant SMTP dans la file** : le worker (`SendTenantEmailHandler` → `TenantEmailDelivery`) relit la
configuration dans la base du site, puis envoie ; une erreur SMTP relance le message, puis le range dans `failed`. File
injoignable : envoi immédiat. Une réponse HTTP n'attend donc plus le serveur SMTP, et un appelant ne voit plus l'échec
d'un envoi (journal `[Email]` et file `failed`). Exception : `app:test-tenant-smtp` envoie sans file
(`createDirectMailer`). En test, le transport `email` est synchrone (`when@test`).
Destinataires d'un domaine réservé (`example.com`, `*.invalid`, `*.test`… : comptes de démonstration et d'essai) :
retirés, envoi abandonné s'il n'en reste aucun (hors tests). Refus définitif du serveur (5xx) : pas de relance, file
`failed` aussitôt. Logo intégré aux courriels : copie allégée générée à 400 px de large
(`EmailLogoHelper`, `email-400-<logo>.png` à côté de l'original ; 732 Ko → 42 Ko pour demo) ; les gabarits utilisent
`logoUrl` tel quel (`cid:` ou adresse absolue), sans y coller le domaine.

- Chaque worker démarre par `docker/worker/consume.sh` : **cache Symfony propre** (`APP_CACHE_DIR`), reconstruit à chaque
  démarrage, `APP_DEBUG=0`. Sans cela, une reconstruction du cache web supprimait des fichiers utilisés par le worker.
- Redémarrage automatique toutes les 15 min (entre deux messages) : le code modifié est pris en compte sans intervention.
  Forcer : `php bin/console messenger:stop-workers`.
- Le nom de la file est le **chemin du DSN** (`%env(MESSENGER_TRANSPORT_DSN)%_esg`) : l'option `stream` est ignorée
  quand le DSN a un chemin.
- Ne jamais vider Redis en entier (`FLUSHALL`) ; le cache applicatif est dans la base 0.
- **Invalidation du cache applicatif** (26/10/2026 ; invalidation ciblée par composant depuis le 28/10/2026) : toute
  écriture Doctrine d'une entité (création, modification, suppression, liaison d'une collection, traductions comprises)
  est traduite en étiquettes par le **registre `Cache/CacheTags`**, seule source de vérité des deux côtés (lectures
  `TenantCacheService::get()` avec `CacheTags::listTags()` / `itemTags()`, écritures `EventSubscriber/CacheInvalidationSubscriber`) :
  - entité déclarée (`ENTITY_TAGS`) : la liste de son composant (`<composant>.list`) et sa fiche (`<composant>.item.<id>`)
    sont vidées ; une traduction ou un enfant (`PARENT`) vide celles de son parent ; l'étiquette nue `<composant>` vide
    tout le composant, à la demande seulement (seeders) ;
  - entité sans lecture en cache (`NO_CACHE_READ` : jetons, codes OTP, journaux, suivi de l'IA) : rien ; de même pour
    les champs `IGNORED_FIELDS` (compteur de consultations des médias, date de dernière connexion) ;
  - entité **non déclarée** : **tout le cache du site et de sa base** (étiquettes du code et de la base posées sur chaque
    entrée ; celle de la base couvre esgboost/esgboost1, arkanoa-media/arkanoa-media1 et les commandes sans code de
    site), avec une ligne `notice` dans le journal (« entité sans étiquette déclarée ») : c'est le filet de sécurité, et
    la liste de ce qui reste à déclarer.
  Les étiquettes sont préfixées par la base (`db.<base>.<composant>…`, `TenantCacheService::componentTag()`), déposées
  dans `Cache/CacheInvalidationQueue` et vidées **au COMMIT réel** de la connexion par le middleware DBAL
  `Cache/Doctrine/InvalidateOnCommitMiddleware` (abandonnées au ROLLBACK) ; `CacheInvalidationFinalizer` vide ce qui
  resterait en fin de requête, de commande ou de message. Une écriture en SQL direct échappe à Doctrine : appeler
  `TenantCacheService::invalidateComponents(CacheTags::…)` (ex. fusion de clients de la caisse) ou `invalidateTenant()`.
  Aucune étiquette à la main hors du registre : garde-fou `tests/Functional/Cache/CacheTagConventionTest.php`.
  - **Composants déclarés** (tous, depuis le 28/10/2026) : Landing Page (présentations, groupes, bannières, slider, atouts,
    cartes, vidéos, équipe, recherches, multiliens, marques, emplois, prestations et familles, véhicules, réglages de la
    page : `landingpage_settings`, 1 h) ; Boutique (produits et fiches `product_detail_{id}_{locale}` 300 s, catégories,
    couleurs, tailles, styles, transporteurs, réglages `boutique_settings` 1 h, référentiels : `CacheTags::productListTags()`
    et `productItemTags()`) ; Commun (entreprise, réglages d'administration) ; commandes et paiements (`orders`, `payments`,
    300 s ; meilleures ventes des favoris de caisse) ; achats (collections, commandes fournisseurs, fournisseurs, notes de
    frais, tableaux de bord). Une lecture qui mêle plusieurs composants porte les étiquettes de chacun.
  - **Plus en cache** : contacts, candidatures, adresses et commandes d'un client, sessions et transactions de caisse
    (données vives ou personnelles, lues en base).
  - **Langue** : `Cache/CacheLocale::normalize()` ramène `?locale=` à `fr` ou `en` avant toute clé (une valeur inventée
    ne crée plus d'entrée).
  - **Réglages gardés en mémoire** (Mémoires Vivantes : `PricingService`, `AiQuotaService`) : par base du site, remis à
    zéro entre deux requêtes ou messages (`ResetInterface`) ; test `tests/Functional/Cache/InMemorySettingsPerSiteTest.php`.
  - **Limiteur de débit** : pool `cache.rate_limiter` propre (`config/packages/cache.yaml`, `REDIS_LIMITER_URL`, repli
    `REDIS_URL`), hors du cache à étiquettes ; en production avec une instance de cache à éviction, le pointer vers une
    instance sans éviction (comme les sessions et les files).
  Tests de fraîcheur, un par route en cache : `tests/Functional/Cache/` (`LandingCacheInvalidationTest`,
  `BoutiqueCacheInvalidationTest`, `OrdersAchatsCacheInvalidationTest`, `CacheInvalidationAfterCommitTest`,
  `CacheInvalidationTest` ; fabrique d'entités `CacheTestEntityFactory`).
  Restent hors de ce mécanisme : le cache HTTP des lectures publiques de la boutique (`PublicReadCacheSubscriber`,
  max-age 60 s et stale-while-revalidate 60 s pour un visiteur anonyme, jamais pour un administrateur connecté) et le
  cache propre au frontend.
- **Écran « Workers »** (EasyAdmin, menu Maintenance ; `Controller/Admin/WorkerAdminController`, `/admin/workers`),
  réservé à `ROLE_SUPER_ADMIN` (entrée de menu, contrôleur et `access_control`) : état de chaque worker, contenu des
  files, rédactions de chapitres en cours ou en échec du site (relance, libération), bouton de redémarrage (même signal
  que `messenger:stop-workers`). L'état vient du battement que
  chaque worker écrit toutes les 5 s dans le cache applicatif (`Services/Worker/WorkerHeartbeatSubscriber`, lu par
  `WorkerMonitor`) : « Arrêté » après 2 min sans battement.
- Redis garde un journal AOF (`docker-compose.yml`) : les messages en attente survivent à un redémarrage de Redis.
- Un worker interrompu en plein traitement reprend son message à son redémarrage (message non acquitté).

## Fichiers et médias

| Stockage | Chemin | Servi par |
|---|---|---|
| Public | `var/storage/public_bucket/` | `Controller/Storage/BucketSimulatorController` (`/bucket-simulator/`, `/uploads/`, `/assets/uploads/`), URL publique `STORAGE_PUBLIC_URL` |
| Privé, par clé | `var/storage/private_media/` | `GET /media/secure/{clé de 64 caractères}` (`SecureMediaDeliveryController`) |
| Boussole ESG | `var/storage/esg/` (documents, rapports) | contrôleurs du module ESG |

Médiathèque partagée : `/api/shared-media` (`SharedMediaApiController`) en lecture ; `POST /api/media`
(`MediaApiController`, ROLE_ADMIN) pour téléverser depuis l'éditeur des landing pages. Fichiers : `Services/SharedMedia/
SharedMediaStorage` (contrôle de l'extension et du type réel, stockage public ou privé), commun à EasyAdmin et à l'API.
URL publiques : `Services/MediaUrlResolver`.

**Vidéo préparée pour une scène au défilement** (administration : case du formulaire ou bouton de la fiche ;
`Services/SharedMedia/ScrollVideoPreparer`). Une scène cale la vidéo sur la position du défilement : il faut des images
complètes très rapprochées. La demande met le média en `scroll_status = pending` et envoie `PrepareScrollVideoMessage`
au worker `media`, qui réencode (`FfmpegScrollVideoEncoder` : H.264, sans images B ni son, 1080p au plus, vidéos de 30 s au plus ;
une image complète toutes les 0,21 s pour un calage rapide ; cadence doublée par interpolation quand la source est à
30 i/s ou moins, pour un défilement lent fluide, d'où un encodage de plusieurs minutes). À la fin, **la même clé et la même adresse** servent le
fichier préparé (`filename`), le fichier d'origine est conservé (`source_filename`) et peut être rétabli. États :
`pending`, `processing`, `done`, `failed`, `too_long` ; en cas d'échec, la vidéo d'origine reste servie.

Cache des médias privés (`GET /media/secure/{clé}`) : `no-store` pour tout, sauf les **vidéos et images affichées** (pas en
téléchargement ; un SVG n'est jamais affiché), que le navigateur du visiteur garde une heure (`private, max-age=3600`, jamais un cache partagé, borné
par l'expiration du lien) puis revalide (304). Mesure du 02/10/2026 pour 6,7 Mo : 0,09 s en direct, 0,11 à 0,15 s par
le proxy, 0,3 à 0,4 s par le relais du frontend ; le débit ne vient donc pas de PHP ni de nginx.

## E-mails

- Chaque site envoie par **son** serveur : table `email_configuration` du tenant (écran EasyAdmin « Email
  Configuration » : serveur SMTP, identifiant, expéditeur), via `Services/EmailConfigurationService/TenantMailerFactory`.
  Le serveur global (`MAILER_DSN`) ne sert que si le site n'a pas de serveur SMTP.
- Rendez-vous (`ReservationMailerService`) : une réservation liée à un livre ou à un biographe est une **séance
  d'écriture** Mémoires Vivantes, programmée avec le client (gabarits `emails/memoires_session_*`) ; sinon c'est une
  demande de réservation d'un visiteur à examiner (gabarits `emails/reservation_*`).
- Tous les e-mails passent par là : rendez-vous (`ReservationMailerService`), contact (`ContactMailerService`), paiement
  des livres (`BookPaymentService`), mot de passe oublié, code de connexion, inscription, commande (`EmailSenderService`).
- Logo : fichier de `var/storage/public_bucket/assets/uploads/email-logos/`, **incorporé à l'e-mail** (image
  `cid:`) par `EmailSenderService` et `BookPaymentService` via `EmailLogoHelper::getLocalPath` ; à défaut de fichier,
  adresse absolue du backend (`getLogoUrl`, qui ne garde du « domaine » fourni par l'appelant que son origine http(s)).
  Les e-mails de rendez-vous et de séance n'affichent pas de logo.
- Les erreurs d'envoi sont journalisées sans interrompre la requête : chercher « Erreur » et « email » dans
  `var/log/dev.log`. Tester l'envoi d'un site : `php bin/console app:test-tenant-smtp <code> <destinataire>`.
- La notification interne (nouveau rendez-vous, paiement reçu) part vers l'e-mail de l'entreprise (`entreprise.email`) :
  vide, elle n'est pas envoyée ou retombe sur l'expéditeur.
- Invitations de calendrier des rendez-vous : fuseau `Europe/Paris` en dur (`ReservationMailerService`), à rendre
  configurable pour un site d'un autre fuseau.
- Tests : `tests/bootstrap.php` retire le serveur SMTP des sites de test (aucun envoi réel).

## Configuration (variables d'environnement)

Dans `.env` (non versionné ; **ne jamais écrire de valeur dans la doc**). Un `.env` invalide casse toutes les commandes
`bin/console`, donc les workers.

| Groupe | Variables |
|---|---|
| Symfony | `APP_ENV`, `APP_SECRET`, `TRUSTED_PROXIES`, `CORS_ALLOW_ORIGIN` |
| Bases, Redis, files | `DATABASE_URL`, `MASTER_DATABASE_URL`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN` |
| Domaines | `BACKEND_BASE_DOMAIN`, `FRONTEND_BASE_DOMAIN`, `YOUR_DOMAIN`, `MEMOIRES_FRONTEND_URL` |
| Authentification | `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `WEB_ACCESS_TOKEN_TTL`, `WEB_SESSION_IDLE_TTL` |
| Tenants | `APP_SETUP_TOKEN`, `TENANT_CREATION_SECRET_KEY` |
| E-mail | `MAILER_DSN` |
| Sécurité | `ADMIN_OTP_REQUIRED` (facultative, `1` : code par e-mail imposé à la connexion de tout administrateur, `Security/TwoFactorPolicy` ; à n'activer qu'une fois l'adresse de chaque administrateur joignable et l'écran du code prêt dans l'éditeur ; formulaire EasyAdmin : `EventSubscriber/PendingOtpSubscriber` garde la session sur `/account/otp` tant que le code n'est pas validé) |
| IA | `ANTHROPIC_API_KEY` (Mémoires Vivantes), `ANTHROPIC_API_KEY_LANDING`, `LANDING_AI_MODEL_EDIT`, `LANDING_AI_MODEL_PAGE`, `LANDING_AI_MODEL_IMAGES`, `LANDING_AI_EFFORT_EDIT` / `_CREATE` / `_PAGE` / `_IMAGES`, `LANDING_AI_CACHE_TTL_PAGE` (facultatives, voir `config/landingpage/README.md`), `OPENAI_API_KEY` (transcription) |
| Traduction | `DEEPL_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_SECRET_KEY` |
| Paiement, livraison | `STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `SQUARE_SECRET_KEY`, `EASYPOST_SECRET_KEY` |
| Caisse en production (M1, facultatives) | `STRIPE_LIVE_SECRET_KEY`, `STRIPE_LIVE_PUBLIC_KEY` (clés **réelles réservées à la caisse**, choisies par site par `StripeAccountResolver` ; la boutique garde `STRIPE_SECRET_KEY`), `STRIPE_TEST_SECRET_KEY` / `STRIPE_TEST_PUBLIC_KEY` (seulement le jour où `STRIPE_SECRET_KEY` deviendrait réelle), `POS_ALERT_PLATFORM_EMAIL` (alertes de surveillance de la caisse à l'administrateur de la plateforme ; jamais modifiable par un site). Procédure : `docs/mise-en-production-caisse.md` |
| Impression (Mémoires Vivantes) | `LULU_CLIENT_KEY`, `LULU_CLIENT_SECRET`, `LULU_API_URL`, `LULU_AUTH_URL`, `LULU_CONTACT_EMAIL`, `LULU_DEFAULT_POD_PACKAGE_ID` |
| Stockage | `STORAGE_PUBLIC_URL`, `STORAGE_ESG_ADAPTER`, `STORAGE_ESG_DIR` |
| Landing Page (synchronisation) | `FRONTEND_CONFIG_URL`, `DEPLOY_SYNC_TOKEN` |

Une variable **déjà présente** à la création d'un conteneur garde son ancienne valeur tant que le conteneur n'est pas
recréé (`docker compose up -d --no-deps --force-recreate <service>`) ; une variable **nouvelle** est lue dans `.env` à
chaque requête.

## Déploiement et exploitation

- Conteneurs : `symfony_app_v2` (PHP-FPM), `symfony_nginx_v2`, `symfony_db_v2`, `redis_cache_v2`, les 4 workers.
- Chaîne HTTP : Nginx Proxy Manager (hôtes `*.backend-strapi.online`) → `symfony_nginx_v2` → PHP-FPM.
  Certificats TLS valides pour `backend-strapi.online`, `v2.backend-strapi.online` et les sous-domaines de tenant ;
  **pas** pour `esgboost.v2.` et `lintendantprive.v2.backend-strapi.online` (déclarés dans NPM). Le relais `/api` du
  frontend vérifie le certificat : viser un hôte couvert.
- Taille des requêtes : NPM 2 000 Mo, nginx 200 Mo, PHP 200 Mo ; l'assistant IA limite lui-même le corps à ~27 Mo.
- CORS (nginx) : `/uploads`, `/assets/uploads`, `/bucket-simulator/…` et `/media/secure/` : `*` sans cookies (fichiers
  publics ou protégés par leur seule clé) ; `/api` et le reste : origine renvoyée seulement si elle figure dans la liste
  de `docker/nginx/default.conf` (à compléter pour chaque nouveau domaine client qui appelle l'API directement).
- Corps trop volumineux : 413 en JSON pour `/api/` (`error_page 413`, redéclaré dans la location PHP), HTML sinon.
  Délais portés à **330 s** : `fastcgi_read_timeout` (`docker/nginx/default.conf`) et fichier personnalisé de NPM
  `/data/nginx/custom/server_proxy.conf` (inclus dans tous les hôtes, conservé par NPM).
- Recharger nginx : `docker exec symfony_nginx_v2 nginx -t && docker exec symfony_nginx_v2 nginx -s reload`.
- **Performances (17/10/2026)** :
  - PHP-FPM : 20 processus, recyclés toutes les 500 requêtes (`docker/php/zz-perf-pool.conf`). Il était limité à 5,
    et les générations IA saturaient le pool.
  - OPcache : 256 Mo, 32 Mo de chaînes, 32 531 fichiers (`docker/php/zz-opcache-perf.ini`). Ces deux fichiers sont
    montés par `docker-compose.yml` ; une modification demande `docker compose up -d --no-deps --force-recreate app`.
  - État du pool, interne seulement :
    `docker exec symfony_app_v2 sh -c 'SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'`
    (surveiller `max children reached`).
  - Profileur Symfony coupé en dev (`config/packages/web_profiler.yaml`) : personne ne pouvait le lire.
  - Journal nginx minuté : IP réelle, en-tête `X-Tenant-Host`, `rt=` (durée totale) et `urt=` (durée PHP), en secondes.
    Routes lentes : `docker logs symfony_nginx_v2 --since 1h | grep 'rt=' | sort -t= -k3 -rn | head`.
- Pousser (`git push origin dev`) sauvegarde le code ; il tourne déjà en production.

| Commande | Rôle |
|---|---|
| `docker exec -w /var/www symfony_app_v2 php bin/console app:docs:endpoints > docs/endpoints.md` (depuis l'hôte) | Régénère `docs/endpoints.md` |
| `php bin/console app:landingpage:check-reglable` | Contrôle toutes les compositions de landing page de tous les tenants |
| `php bin/console app:landingpage-ai:eval --tenant=<tenant de test>` | Jeu d'essai de l'assistant IA (appels réels) |
| `php bin/console app:tenant:migrate-all` | Migrations sur tous les tenants |
| `php bin/console app:entreprise:check-legal-texts` | Inventaire des textes légaux de chaque site que le filtrage HTML refuserait (lecture seule) |
| `php bin/console app:media:prepare-scroll <tenant> <id du média>` | Demande la préparation d'une vidéo de la médiathèque pour le défilement |
| `php bin/console messenger:stop-workers` | Redémarre les workers (après vérification qu'aucune génération n'est en cours) |

Commandes à lancer dans le conteneur : `docker exec -w /var/www symfony_app_v2 php bin/console …`.

## Tests

```
docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 php vendor/bin/phpunit tests/Functional
```

- `tests/bootstrap.php` isole tout de la production : base maître `master_mv_test` (tenants fictifs `mvtest` et
  `mvtest2`), bases `db_mv_contract_test` et `db_mv_contract_test2` clonées de `db_mv_test_booktypes` (ne jamais
  supprimer ce modèle), Redis base 2, Messenger en mémoire, e-mails vers `null://`.
- Services simulés (`config/services_test.yaml`) : `FakeAnthropicService`, `FakeLandingAiClient`,
  `FakeFrontendConfigHttpClient`. Aucun appel réel ; test réel optionnel : `LANDING_AI_REAL_TEST=1`.
- Dossiers : `tests/Functional/LandingPage`, `MemoiresVivantes`, `Security`, `Contact`, `Admin`, `Email`, `Worker`.
- Limite de connexions par IP relevée en test seulement (`when@test` dans `config/packages/rate_limiter.yaml`) : toute
  la suite se connecte depuis la même IP.
- Les variables du conteneur (`env_file`) priment sur `.env.test` : `phpunit.xml.dist` force `ADMIN_OTP_REQUIRED=0` et
  `LANDING_AI_MODEL_IMAGES` vide, sinon le réglage de production fait échouer les connexions et les modèles attendus.
  Y ajouter toute nouvelle variable de production qui change un comportement testé.

## Modules (fiches)

| Module | Code | Fiche |
|---|---|---|
| Landing Page (réglages, validation, assistant IA, synchronisation) | `Services/Landing*`, `Controller/Landing*`, `config/landingpage/` | `docs/landingpage.md` |
| Mémoires Vivantes | `src/MemoiresVivantes/` | `docs/memoires-vivantes.md` |
| Boussole ESG | `src/ESG/` | `docs/boussole-esg.md` |
| Boutique (produits, commandes, paiement, livraison ; aucune en production) | `Controller/*`, `Services/*` hors modules ci-dessus | `docs/boutique.md` ; routes dans `docs/endpoints.md` |
| Caisse (postes, sessions, ventes et remboursements du POS) | `Entity/CashRegister`, `Entity/Caisse`, `Services/CaisseService/`, `UseCase/CaisseUseCase/`, `Controller/CaisseController/`, `Controller/CashRegisterController/` | `docs/caisse.md` |

## Contrats partagés avec le frontend

`config/landingpage/` : schéma des compositions réglables, catalogue de l'assistant IA, jeu d'essai, synchronisés
depuis le frontend (voir `docs/landingpage.md`).

Fiches correspondantes côté frontend (dépôt `Izzipro_next`) :

| Module | Fiche frontend |
|---|---|
| Landing Page | `src/components/LandingPage/README.md` |
| Mémoires Vivantes | `src/components/MemoiresVivantes/README.md` |
| Boussole ESG | `src/components/BoussoleESG/README.md` |
| Boutique | `src/components/Boutique/README.md` |
