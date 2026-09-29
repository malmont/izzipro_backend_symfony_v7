# Compositions « réglables » des landing pages

`landingpage-reglable.schema.json` est une **copie exacte** du JSON Schema (draft 2020-12) du frontend
`Izzipro_next/docs/landingpage-reglable.schema.json`, généré par `npm run schema:reglable`.
Ne jamais le modifier à la main côté backend : le frontend est la référence.

Utilisé par `App\Services\LandingPageSettingsService\ReglableCompositionValidator` (bibliothèque
`opis/json-schema` 2.x) à chaque PUT de `/api/landingpage-settings`, sur :

- `tabs[].sections[].reglableConfig` des sections `componentTypeKey = "typeReglable"` ;
- `navbar.reglableConfig`, `footer.reglableConfig` ;
- `reglablePresets[].config`.

Règles vérifiées en code (non exprimables en JSON Schema) : identifiants uniques, `parentId` = `null` ou id
d'un bloc `container` de la composition, pas de boucle, 8 niveaux de parents au plus, `x + w ≤ 100`,
`y + h ≤ 100`, parenthèses équilibrées des dégradés.

Les `pattern` du schéma sont des expressions JavaScript (norme JSON Schema) ; le validateur traduit en mémoire
`\uXXXX` en `\x{XXXX}` pour PHP (PCRE). Seule nuance restante : `\s` de PHP ne couvre pas les espaces Unicode
(espace insécable…) que JavaScript accepte dans un dégradé.

Erreur : HTTP 422 `{ "error", "message", "errors": [{ "path", "message" }] }`, chemin au format du frontend,
par exemple `tabs[0].sections[2].reglableConfig.blocks[3].fontColor`. Rien n'est enregistré.

## Mettre à jour le schéma quand le frontend le change

1. Côté frontend : `npm run schema:reglable` (le test du frontend vérifie que le fichier est à jour).
2. Copier `docs/landingpage-reglable.schema.json` du frontend ici, **à l'identique**, sous le même nom.
3. Si le frontend a ajouté des règles non exprimables en schéma (`validateCanvas` dans `canvasConfig.js`),
   les reporter dans `ReglableCompositionValidator`.
4. Vérifier que les compositions en production restent valides, puis lancer les tests :
   ```
   docker exec symfony_app_v2 php bin/console app:landingpage:check-reglable
   docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 \
       php vendor/bin/phpunit tests/Functional/LandingPage
   ```
   La commande liste, pour chaque site, les compositions que le nouveau schéma refuserait. À corriger
   (depuis l'éditeur ou par le frontend) **avant** de déployer le schéma, sinon ces sites ne pourront plus
   enregistrer leur page sans corriger d'abord les erreurs signalées.
5. Mettre à jour `tests/Fixtures/landingpage/production-compositions.json` si de nouvelles compositions
   représentatives existent (commande ci-dessus avec `--export`).
6. Committer le schéma avec les éventuelles adaptations. Aucune migration de base : le stockage JSON est inchangé.

## Assistant IA de l'éditeur (fichiers de référence)

- `landingpage-ia-catalogue.json` : **copie exacte** du catalogue du frontend (`Izzipro_next/docs/landingpage-ia-catalogue.json`,
  généré par `npm run ia:catalogue`). Pour chaque famille (`componentKey`) : types de blocs autorisés (`tools`), champs liables
  (`sectionFields`, `boundTools`, `list`) et modèles de référence (`presets`). Lu par `App\Services\LandingAiService\LandingAiCatalogue`.
- `ia-assistant-jeu-essai.md` : jeu d'essai (cas R1–R10, C1–C8, P1–P3 et vérifications V1–V6), rejoué par
  `php bin/console app:landingpage-ai:eval --tenant=<tenant de test> [--mode=edit|create|page|all] [--case=R1]` (sans HTTP ni quota, aucune écriture ;
  rapport JSON dans `var/landing-ai-eval/`). Images des cas P1 (charte) et P2 (capture) :
  `src/Services/LandingAiService/Eval/fixtures/`.

### Synchronisation depuis le frontend (remplace les copies manuelles)

Les fichiers de ce dossier ne servent plus que de **version de repli** (`bundled`) : serveur sans synchronisation
(nouvelle installation) et tests. Ce sont des copies de la dernière version synchronisée et validée (29/09/2026 :
`a966c990f8a0934f`, première synchronisation réelle) ; les recopier depuis `var/landingpage-config/versions/<id>/`
quand le frontend change de version. La version active est lue dans `var/landingpage-config/` (`LandingConfigStore`)
par le validateur, le catalogue et le constructeur de prompts, sans redémarrage (worker compris).

- Le frontend publie sous `FRONTEND_CONFIG_URL` (ex. `https://<frontend>/reglable-config/`) : `manifest.json`
  `{ "version", "files": { "<nom>": "<sha256>" } }` et les 3 fichiers `landingpage-reglable.schema.json`,
  `landingpage-ia-catalogue.json`, `ia-assistant-jeu-essai.md`.
- `GET /api/landingpage-config/status` → `{ active: { id, version, files }, available: { version, files } | null,
  availableError, upToDate, previous, history }` (20 dernières actions).
- `POST /api/landingpage-config/sync` : télécharge depuis `FRONTEND_CONFIG_URL` **uniquement** (le corps de la requête est
  ignoré), vérifie chaque sha256 (422 sinon), vérifie que le schéma et le catalogue sont utilisables (422), puis contrôle
  avec le nouveau schéma **toutes les compositions de tous les tenants et tous les modèles du catalogue** : une seule
  refusée → 409 avec la liste (`errors`, `refusedCount`), rien n'est activé. Sinon activation atomique ; l'ancienne
  version devient `previous`. Même version déjà active → `up_to_date`.
- `POST /api/landingpage-config/rollback` : revient à la version précédente (même contrôle : 409 si une composition
  enregistrée depuis serait refusée). Un second retour revient à la version quittée.
- Accès : utilisateur `ROLE_SUPER_ADMIN`, ou en-tête `X-Deploy-Token: <DEPLOY_SYNC_TOKEN>` (GitHub Action ; jeton de 32
  caractères au moins, sinon désactivé). GitHub Action :
  `curl -fsS -X POST -H "X-Deploy-Token: $DEPLOY_SYNC_TOKEN" https://v2.backend-strapi.online/api/landingpage-config/sync`
- Historique : table `landing_config_sync` de la base maître (`app_v2_db`, pas dans les bases tenant) :
  `CREATE TABLE IF NOT EXISTS landing_config_sync (id SERIAL PRIMARY KEY, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(10) NOT NULL, version VARCHAR(100) DEFAULT NULL, author VARCHAR(255) NOT NULL, result VARCHAR(32) NOT NULL,
  details TEXT DEFAULT NULL)`.
- Après une synchronisation : rejouer le jeu d'essai (`app:landingpage-ai:eval`) et comparer le rapport au précédent.
  `app:landingpage:check-reglable` contrôle les compositions avec la version active.

### Fonctionnement (étapes 1 à 3 : retouche, création, page et images)

- `POST /api/landingpage-ai/compose` (ROLE_ADMIN du tenant) :
  - **edit** `{ mode: "edit", componentKey, composition, prompt, locale?, media? }` : l'IA renvoie des opérations
    (`update`, `add`, `remove`, `section`) appliquées par le serveur sur la composition envoyée. `set` **fusionne**
    récursivement les objets imbriqués (mobile, repeat, bindings, translations…) et **remplace** les tableaux (links,
    images, iconCycle…) ; `unset` accepte des chemins pointés (`"mobile.w"`, `"bindings.offer"`).
  - **create** `{ mode: "create", componentKey, dataType?, prompt, locale?, media? }` : l'IA compose une section complète
    et choisit la donnée affichée (`dataType`) parmi les données du site pour cette famille. Familles à donnée : celles
    de `components-config` avec `is_data_selectable` et un `api_data_endpoint` (`ComponentsConfigProvider`, source du
    panneau de l'éditeur), sauf Baniere, Service et Carousel dont la version réglable ignore la donnée ; la donnée de
    Reservation (prestation) est facultative (`null` = toutes les prestations). Les autres familles (Contact, Recherche,
    À propos…) exigent `dataType: null`. Le `dataType` envoyé sert de donnée par défaut ; la réponse contient le
    `dataType` retenu.
  - **page** `{ mode: "page", prompt, componentKey?, images?, locale?, media? }` : l'IA (modèle page) compose plusieurs
    sections dans l'ordre de la page ; réponse `sections: [{ componentKey, dataType, composition }]` au lieu de
    `composition`. Sans `componentKey`, elle choisit la famille de chaque section dans le catalogue ; avec, toutes les
    sections sont de cette famille (ex. « Reproduis cette section » sur une capture). Chaque section est vérifiée comme
    une création. Contexte fixe mis en cache : le catalogue de toutes les familles avec un modèle de référence chacune.
  - **images** (tous les modes) : `images: ["data:image/png;base64,…"]`, 5 au plus, PNG, JPEG, WebP ou GIF, 5 Mo au
    plus chacune en base64, 8000 px au plus de côté ; le contenu doit correspondre au type annoncé. Charte graphique :
    l'IA n'emploie que ses couleurs et polices (plus blanc, noir, gris neutres). Capture d'écran : l'IA en reproduit la
    structure ; les images de la capture ne sont pas des médias utilisables. Une requête avec images passe au modèle
    page et coûte 10 crédits.
  - La proposition passe par `ReglableCompositionValidator`, les types de la famille (`tools` du catalogue) et la liste
    des médias autorisés
    (médias du site, médias fournis avec la demande, adresses http(s) écrites dans la demande et, en création ou page,
    images des modèles des familles concernées), avec 3 essais au total (erreurs renvoyées au modèle ; en page, chemins
    `sections[i].…`). Les réglages du site ne sont jamais enregistrés par cet endpoint. `translations` n'est rempli que
    si la demande le demande explicitement.
  - **Tâches de fond** : le mode page et toute requête avec images répondent **202** `{ jobId, credits }` (en-tête
    `Location`) après les contrôles et la réservation des crédits ; le worker Messenger dédié (transport `landing_ai`,
    conteneur `symfony_messenger_worker_landing_v2`) traite la
    demande. `GET /api/landingpage-ai/jobs/{jobId}` → `{ jobId, status: pending | running | done | failed, result?
    (même contenu que la réponse synchrone), error? { status, error, message, errors? } }`, réservé aux administrateurs
    du tenant de la tâche (404 pour un autre tenant). Résultat conservé 1 heure ; la requête (images comprises) est
    effacée dès le traitement. Tâche en attente depuis 30 min (worker arrêté) ou en cours depuis 10 min : échec 504,
    crédits libérés. Réservation des crédits : 35 min pour une tâche de fond, 5 min en synchrone
    (`ai_usage.reserved_until`). Edit et create sans image restent synchrones (200).
  - Délais : retouche et création 90 s par appel, 180 s au total ; page et requêtes avec images 180 s par appel, 300 s
    au total (504 au-delà, crédits libérés). **Les intermédiaires doivent laisser passer 330 s** : `fastcgi_read_timeout
    330s` dans `docker/nginx/default.conf` (le défaut de 60 s coupait la réponse en 504 pendant que PHP terminait et
    consommait les crédits), `proxy_read_timeout` du proxy public (Nginx Proxy Manager : 90 s par défaut) et délai
    d'attente du frontend.
- `GET /api/landingpage-ai/usage` : crédits du mois et 50 dernières demandes.
- Quota : 100 crédits par mois (fuseau America/Toronto), modifiable par tenant en SQL :
  `INSERT INTO ai_credit_setting (monthly_credits) VALUES (200)` (ou `UPDATE` si la ligne existe). Coût : retouche 1,
  création 3, page ou images 10. Limite : 5 demandes par minute et par tenant.
- Variables : `ANTHROPIC_API_KEY_LANDING` (clé dédiée), `LANDING_AI_MODEL_EDIT` (défaut `claude-sonnet-5`),
  `LANDING_AI_MODEL_PAGE` (défaut `claude-opus-5-5` : mode page et requêtes avec images).
- Tables par tenant : `ai_usage` (historique, supprimé après 90 jours, jamais de composition ni de réponse du modèle),
  `ai_credit_setting` et `ai_job` (tâches de fond) — `scripts/migrate_all_v2_landing_ai.sh` / migrations
  `Version20260928200000` et `Version20260929150000`.
- Variables de la synchronisation : `FRONTEND_CONFIG_URL`, `DEPLOY_SYNC_TOKEN`.
