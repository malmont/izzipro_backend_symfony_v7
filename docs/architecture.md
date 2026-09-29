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
| Base maître (table `tenants` : `code`, `dbname`, `custom_domain`…) | `MASTER_DATABASE_URL` → base `app_v2_db` (la base `master` est un ancien reste) |
| Résolution du tenant d'une requête | `EventListener/TenantDoctrineSwitcherListener` : en-tête `X-Tenant-Host`, sinon `?tenant=`/`?t=` ou session (EasyAdmin), sinon l'hôte ; domaine principal du backend → `tenantdefaut` |
| Hôte → tenant | `TenantConnectionManager::findTenantConfigByHost` (cache Redis 1 h) : 1) `custom_domain` (avec ou sans `www.`), 2) sous-domaine = `code` (suffixe `-v2` ignoré, `-`/`_` équivalents) |
| Base du tenant courant | `TenantConnectionProvider` (bascule la connexion Doctrine par défaut) ; code du tenant : `getTenantCode()` |

**Règle** : toujours passer par `App\Services\TenantEntityManagerProvider` (`getEntityManager()`, `getConnection()`),
jamais par l'EntityManager par défaut ni `ManagerRegistry`. Commandes et workers : `switchTenant($dbname, $code)`.
CRUD EasyAdmin : étendre `BaseTenantCrudController`.

- **Nouveau tenant** : `CREATE DATABASE … WITH TEMPLATE gmasuite` (`TenantConnectionManager::createTenant`,
  `app:tenant:create`) ; ce que contient `gmasuite` devient la valeur par défaut des nouveaux clients.
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
  - À surveiller : les scripts lisent `tenants` dans l'ancienne base `master`, l'application dans `app_v2_db` (listes
    identiques au 29/09/2026).
  - La base modèle `gmasuite` est dans `tenants` : un nouveau tenant hérite donc du schéma à jour.
- Tables de plateforme (pas dans les bases tenant) : dans `app_v2_db`, via `TenantConnectionManager::getPdoMaster()`
  (ex. `landing_config_sync`).

## Authentification et sécurité

| Sujet | Où |
|---|---|
| Pare-feux | `config/packages/security.yaml` : `api` (`^/api` sauf routes publiques listées, JWT sans état), `main` (reste : formulaire Twig, session) |
| Utilisateurs | par tenant : `Security/TenantUserProvider` (table des utilisateurs de la base du tenant) |
| Connexion web | `POST /api/login` → cookies `auth_token_<tenant>` (JWT) et `XSRF-TOKEN_<tenant>` ; `POST /api/token/refresh` ; `POST /api/logout` (`Controller/Account/SecurityController`) |
| JWT lu depuis le cookie | `EventListener/JWTFromCookieListener` (si pas d'en-tête `Authorization`) ; durées : `WEB_ACCESS_TOKEN_TTL`, `WEB_SESSION_IDLE_TTL` |
| CSRF | `EventListener/CsrfValidationListener` : sur `/api/*` hors GET, si le cookie d'auth du tenant est présent, en-tête `X-XSRF-TOKEN` = cookie `XSRF-TOKEN_<tenant>` |
| Rôles | `ROLE_ADMIN` (administrateur du site), `ROLE_SUPER_ADMIN` (propriétaire de la plateforme, testé explicitement : pas de `role_hierarchy`), `ROLE_USER_INTERNET` (nécessaire à la connexion web), `ROLE_USER_POS`, rôles Boussole (`ROLE_COMPANY`, `ROLE_CONSULTANT`) |
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
| `failed` (`messages_failed`) | — | messages en échec (inspection) |

- Chaque worker démarre par `docker/worker/consume.sh` : **cache Symfony propre** (`APP_CACHE_DIR`), reconstruit à chaque
  démarrage, `APP_DEBUG=0`. Sans cela, une reconstruction du cache web supprimait des fichiers utilisés par le worker.
- Redémarrage automatique toutes les 15 min (entre deux messages) : le code modifié est pris en compte sans intervention.
  Forcer : `php bin/console messenger:stop-workers`.
- Le nom de la file est le **chemin du DSN** (`%env(MESSENGER_TRANSPORT_DSN)%_esg`) : l'option `stream` est ignorée
  quand le DSN a un chemin.
- Ne jamais vider Redis en entier (`FLUSHALL`) ; le cache applicatif est dans la base 0.

## Fichiers et médias

| Stockage | Chemin | Servi par |
|---|---|---|
| Public | `var/storage/public_bucket/` | `Controller/Storage/BucketSimulatorController` (`/bucket-simulator/`, `/uploads/`, `/assets/uploads/`), URL publique `STORAGE_PUBLIC_URL` |
| Privé, par clé | `var/storage/private_media/` | `GET /media/secure/{clé de 64 caractères}` (`SecureMediaDeliveryController`) |
| Boussole ESG | `var/storage/esg/` (documents, rapports) | contrôleurs du module ESG |

Médiathèque partagée : `/api/shared-media` (`SharedMediaApiController`). URL publiques : `Services/MediaUrlResolver`.

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
| IA | `ANTHROPIC_API_KEY` (Mémoires Vivantes), `ANTHROPIC_API_KEY_LANDING`, `LANDING_AI_MODEL_EDIT`, `LANDING_AI_MODEL_PAGE`, `OPENAI_API_KEY` (transcription) |
| Traduction | `DEEPL_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_SECRET_KEY` |
| Paiement, livraison | `STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET`, `SQUARE_SECRET_KEY`, `EASYPOST_SECRET_KEY` |
| Impression (Mémoires Vivantes) | `LULU_CLIENT_KEY`, `LULU_CLIENT_SECRET`, `LULU_API_URL`, `LULU_AUTH_URL`, `LULU_CONTACT_EMAIL`, `LULU_DEFAULT_POD_PACKAGE_ID` |
| Stockage | `STORAGE_PUBLIC_URL`, `STORAGE_ESG_ADAPTER`, `STORAGE_ESG_DIR` |
| Landing Page (synchronisation) | `FRONTEND_CONFIG_URL`, `DEPLOY_SYNC_TOKEN` |

Une variable **déjà présente** à la création d'un conteneur garde son ancienne valeur tant que le conteneur n'est pas
recréé (`docker compose up -d --no-deps --force-recreate <service>`) ; une variable **nouvelle** est lue dans `.env` à
chaque requête.

## Déploiement et exploitation

- Conteneurs : `symfony_app_v2` (PHP-FPM), `symfony_nginx_v2`, `symfony_db_v2`, `redis_cache_v2`, les 3 workers.
- Chaîne HTTP : Nginx Proxy Manager (hôtes `*.backend-strapi.online`) → `symfony_nginx_v2` → PHP-FPM.
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

## Modules (fiches)

| Module | Code | Fiche |
|---|---|---|
| Landing Page (réglages, validation, assistant IA, synchronisation) | `Services/Landing*`, `Controller/Landing*`, `config/landingpage/` | `docs/landingpage.md` |
| Mémoires Vivantes | `src/MemoiresVivantes/` | `docs/memoires-vivantes.md` |
| Boussole ESG | `src/ESG/` | `docs/boussole-esg.md` (à écrire) |
| Boutique (produits, commandes, paiement, livraison, caisse) | `Controller/*`, `Services/*` hors modules ci-dessus | à écrire ; routes dans `docs/endpoints.md` |

## Contrats partagés avec le frontend

`config/landingpage/` : schéma des compositions réglables, catalogue de l'assistant IA, jeu d'essai, synchronisés
depuis le frontend (voir `docs/landingpage.md`). Côté frontend : `src/components/LandingPage/README.md` (dépôt
`Izzipro_next`).
