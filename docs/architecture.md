# Architecture du backend (app_v2)

Ce qui est commun à tout le backend. Chaque module a sa fiche (voir « Modules »), les routes sont dans
`docs/endpoints.md`. À tenir à jour à chaque changement structurel.

## En bref

- **Symfony 6.4, PHP 8.3, PostgreSQL 15, Redis 7**, dans Docker (`docker-compose.yml`). Une seule application sert
  **plusieurs clients (tenants)**, chacun avec **sa propre base**.
- Frontend : autre dépôt (Next.js, `Izzipro_next`) ; il appelle `/api/*` avec l'en-tête `X-Tenant-Host`. Sa doc :
  `AGENTS.md` et `docs/architecture.md` de ce dépôt-là.
- ⚠️ **Le code est monté en direct dans les conteneurs** (`.:/var/www`) et la production tourne en `APP_ENV=dev` :
  **toute modification de fichier est immédiatement en production**, y compris `config/`.

## Multi-tenant

| Élément | Où |
|---|---|
| Base maître (table `tenants` : `code`, `dbname`, `custom_domain`…) | `MASTER_DATABASE_URL` → base `app_v2_db` (l'ancienne base `master` n'est plus lue par rien : ne pas y écrire) |
| Résolution du tenant d'une requête | `EventListener/TenantDoctrineSwitcherListener` : en-tête `X-Tenant-Host`, sinon `?tenant=`/`?t=` ou session (EasyAdmin), sinon l'hôte ; domaine principal du backend → `tenantdefaut` |
| Hôte → tenant | `TenantConnectionManager::findTenantConfigByHost` (cache Redis 1 h) : 1) `custom_domain` (avec ou sans `www.`), 2) sous-domaine = `code` (suffixe `-v2` ignoré, `-`/`_` équivalents) |
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

Ce que contient `gmasuite` devient la valeur par défaut des nouveaux clients (`TenantConnectionManager::createTenant`).
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
     `…_shared_media.sh`, `…_video_fields.sh`, `…_landing_ai.sh`, `migrate_v2_reservation_steps.sh`.
  - Les scripts lisent la liste des bases dans `app_v2_db` (comme l'application) depuis le 30/09/2026.
  - La base modèle `gmasuite` est dans `tenants` : un nouveau tenant hérite donc du schéma à jour.
- Tables de plateforme (pas dans les bases tenant) : dans `app_v2_db`, via `TenantConnectionManager::getPdoMaster()`
  (ex. `landing_config_sync`).

## Authentification et sécurité

| Sujet | Où |
|---|---|
| Pare-feux | `config/packages/security.yaml` : `api` (`^/api` sauf routes publiques listées, JWT sans état), `main` (reste : formulaire Twig, session) |
| Utilisateurs | par tenant : `Security/TenantUserProvider` (table des utilisateurs de la base du tenant) |
| Connexion web | `POST /api/login` → cookies `auth_token_<tenant>` (JWT) et `XSRF-TOKEN_<tenant>` ; `POST /api/token/refresh` ; `POST /api/logout` (`Controller/Account/SecurityController`) |
| JWT lu depuis le cookie | `EventListener/JWTFromCookieListener` (si pas d'en-tête `Authorization`) ; durées : `WEB_ACCESS_TOKEN_TTL`, `WEB_SESSION_IDLE_TTL` ; le jeton porte `tenant_code` et n'est valable que sur ce site ; un jeton sans `tenant_code` (émis hors de tout site) est refusé sur un site (`JWTDecodedListener`) |
| CSRF | `EventListener/CsrfValidationListener` : sur `/api/*` hors GET, si le cookie d'auth du tenant est présent, en-tête `X-XSRF-TOKEN` = cookie `XSRF-TOKEN_<tenant>` |
| Rôles | `ROLE_ADMIN` (administrateur du site), `ROLE_SUPER_ADMIN` (propriétaire de la plateforme, testé explicitement : pas de `role_hierarchy` ; ni attribuable ni retirable par un admin de site : `Security/RoleAssignmentPolicy`, appliquée par l'écran EasyAdmin des utilisateurs et par `/api/memoires/admin/users` (compte super admin : ni modifiable ni supprimable par un admin de site) ; sinon en SQL. Détenu par le seul compte du propriétaire, dans toutes les bases, `gmasuite` comprise, donc aussi dans chaque nouveau site), `ROLE_USER_INTERNET` (nécessaire à la connexion web), `ROLE_USER_POS`, rôles Boussole (`ROLE_COMPANY`, `ROLE_CONSULTANT`) |
| Limites de débit | `config/packages/rate_limiter.yaml` (connexion, OTP, mot de passe, formulaires publics par IP, assistant IA par tenant) ; `EventSubscriber/PublicEndpointRateLimitSubscriber` |

**`access_control` : la première règle qui correspond l'emporte.** L'ordre compte : une règle précise (ex.
`^/api/landingpage-ai`) doit précéder la règle publique générale qui contient `landingpage`. Le rôle exigé par chaque
route est calculé dans `docs/endpoints.md`. Garde-fou : `tests/Functional/Security/AnonymousWriteAccessTest.php`
refuse toute route d'écriture sans rôle qui n'est pas une exception justifiée.

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
| `failed` (`messages_failed`) | — | messages en échec (inspection) |

Le worker `media` a sa propre image (`docker/worker-media/Dockerfile` : l'image de l'application plus **ffmpeg**) : c'est
le seul conteneur qui encode des vidéos, le site n'encode jamais rien. Après une reconstruction de l'image de
l'application : `docker compose build messenger_worker_media && docker compose up -d --no-deps messenger_worker_media`.

- Chaque worker démarre par `docker/worker/consume.sh` : **cache Symfony propre** (`APP_CACHE_DIR`), reconstruit à chaque
  démarrage, `APP_DEBUG=0`. Sans cela, une reconstruction du cache web supprimait des fichiers utilisés par le worker.
- Redémarrage automatique toutes les 15 min (entre deux messages) : le code modifié est pris en compte sans intervention.
  Forcer : `php bin/console messenger:stop-workers`.
- Le nom de la file est le **chemin du DSN** (`%env(MESSENGER_TRANSPORT_DSN)%_esg`) : l'option `stream` est ignorée
  quand le DSN a un chemin.
- Ne jamais vider Redis en entier (`FLUSHALL`) ; le cache applicatif est dans la base 0.
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
| IA | `ANTHROPIC_API_KEY` (Mémoires Vivantes), `ANTHROPIC_API_KEY_LANDING`, `LANDING_AI_MODEL_EDIT`, `LANDING_AI_MODEL_PAGE`, `LANDING_AI_MODEL_IMAGES`, `LANDING_AI_EFFORT_EDIT` / `_CREATE` / `_PAGE` / `_IMAGES`, `LANDING_AI_CACHE_TTL_PAGE` (facultatives, voir `config/landingpage/README.md`), `OPENAI_API_KEY` (transcription) |
| Traduction | `DEEPL_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_SECRET_KEY` |
| Paiement, livraison | `STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `SQUARE_SECRET_KEY`, `EASYPOST_SECRET_KEY` |
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
- Dossiers : `tests/Functional/LandingPage`, `MemoiresVivantes`, `Security`.
- Limite de connexions par IP relevée en test seulement (`when@test` dans `config/packages/rate_limiter.yaml`) : toute
  la suite se connecte depuis la même IP.

## Modules (fiches)

| Module | Code | Fiche |
|---|---|---|
| Landing Page (réglages, validation, assistant IA, synchronisation) | `Services/Landing*`, `Controller/Landing*`, `config/landingpage/` | `docs/landingpage.md` |
| Mémoires Vivantes | `src/MemoiresVivantes/` | `docs/memoires-vivantes.md` |
| Boussole ESG | `src/ESG/` | `docs/boussole-esg.md` |
| Boutique (produits, commandes, paiement, livraison, caisse ; aucune en production) | `Controller/*`, `Services/*` hors modules ci-dessus | à écrire ; routes dans `docs/endpoints.md` |

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
