# Compositions « réglables » des landing pages

`landingpage-reglable.schema.json` est une **copie exacte** du JSON Schema (draft 2020-12) du frontend
`Izzipro_next/docs/landingpage-reglable.schema.json`, généré par `npm run schema:reglable`.
Ne jamais le modifier à la main côté backend : le frontend est la référence.

Utilisé par `App\Services\LandingPageSettingsService\ReglableCompositionValidator` (bibliothèque
`opis/json-schema` 2.x) à chaque PUT de `/api/landingpage-settings` et de `/api/boutique-settings` (boutique
réglable, `docs/boutique.md` : mêmes compositions, plus les pages système, la charte et le commerce), sur :

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
  `php bin/console app:landingpage-ai:eval [--tenant=demo] [--mode=edit|create|page|all] [--case=R1]` (sans HTTP ni quota, aucune écriture ;
  rapport JSON dans `var/landing-ai-eval/`). Images des cas P1 (charte) et P2 (capture) :
  `src/Services/LandingAiService/Eval/fixtures/`.

### Synchronisation depuis le frontend (remplace les copies manuelles)

Les fichiers de ce dossier ne servent plus que de **version de repli** (`bundled`) : serveur sans synchronisation
(nouvelle installation) et tests. Ce sont des copies de la dernière version synchronisée et validée (08/10/2026 :
`528b8f8009eb1e0a`, catalogue version 2 avec les familles de la boutique et `systemPages` ; première synchronisation
réelle le 29/09/2026 : `a966c990f8a0934f`) ; les recopier depuis `var/landingpage-config/versions/<id>/` quand le
frontend change de version (les tests de la boutique exigent un catalogue avec `systemPages`). La version active est lue dans `var/landingpage-config/` (`LandingConfigStore`)
par le validateur, le catalogue et le constructeur de prompts, sans redémarrage (worker compris).

- Le frontend publie sous `FRONTEND_CONFIG_URL` (ex. `https://<frontend>/reglable-config/`) : `manifest.json`
  `{ "version", "files": { "<nom>": "<sha256>" } }` et les 3 fichiers `landingpage-reglable.schema.json`,
  `landingpage-ia-catalogue.json`, `ia-assistant-jeu-essai.md`, plus un fichier **facultatif** (07/10/2026)
  `ia-libelles-editeur.md` : libellés exacts de l'éditeur, ajoutés aux consignes de l'assistant (`limits[].howTo`),
  texte UTF-8 de 40 000 octets au plus (422 sinon). Absent du manifeste : la copie de ce dossier sert
  (`LandingConfigStore::path`). Tout autre nom est refusé (422 `fichier inconnu`).
- `GET /api/landingpage-config/status` → `{ active: { id, version, files }, available: { version, files } | null,
  availableError, upToDate, previous, history }` (20 dernières actions).
- `POST /api/landingpage-config/sync` : télécharge depuis `FRONTEND_CONFIG_URL` **uniquement** (le corps de la requête est
  ignoré), vérifie chaque sha256 (422 sinon), vérifie que le schéma et le catalogue sont utilisables (422), puis contrôle
  avec le nouveau schéma **toutes les compositions de tous les tenants** (réglages des landing pages, réglages de la
  boutique `boutique.…` et modèles de site des deux applications `<app>-site-models[id].…`, depuis le 08/10/2026)
  **et tous les modèles du catalogue** : une seule
  refusée → 409 avec la liste (`errors`, `refusedCount`), rien n'est activé. Sinon activation atomique ; l'ancienne
  version devient `previous`. Même version déjà active → `up_to_date`.
- `POST /api/landingpage-config/rollback` : revient à la version précédente (même contrôle : 409 si une composition
  enregistrée depuis serait refusée). Un second retour revient à la version quittée.
- Accès : utilisateur `ROLE_SUPER_ADMIN`, ou en-tête `X-Deploy-Token: <DEPLOY_SYNC_TOKEN>` (GitHub Action ; jeton de 32
  caractères au moins, sinon désactivé ; 10 jetons invalides par IP et par 15 minutes, puis 429).
- Une base de site illisible pendant le contrôle bloque l'activation (409 `Bases illisibles`) : rien n'est activé sans
  avoir contrôlé toutes les compositions. GitHub Action :
  `curl -fsS -X POST -H "X-Deploy-Token: $DEPLOY_SYNC_TOKEN" https://v2.backend-strapi.online/api/landingpage-config/sync`
- Historique : table `landing_config_sync` de la base maître (`app_v2_db`, pas dans les bases tenant) :
  `CREATE TABLE IF NOT EXISTS landing_config_sync (id SERIAL PRIMARY KEY, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(10) NOT NULL, version VARCHAR(100) DEFAULT NULL, author VARCHAR(255) NOT NULL, result VARCHAR(32) NOT NULL,
  details TEXT DEFAULT NULL)`.
- Après une synchronisation : rejouer le jeu d'essai (`app:landingpage-ai:eval`) et comparer le rapport au précédent.
  `app:landingpage:check-reglable` contrôle les compositions avec la version active.

### Fonctionnement (étapes 1 à 3 : retouche, création, page et images)

- `POST /api/landingpage-ai/compose` (ROLE_ADMIN du tenant) :
  - **edit** `{ mode: "edit", componentKey, composition, prompt, locale?, media?, dataType? }` : l'IA renvoie des opérations
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
  - **images** (tous les modes ; le frontend les réduit à 2 000 px en JPEG avant envoi) : `images: ["data:image/png;base64,…"]`, 5 au plus, PNG, JPEG, WebP ou GIF, 5 Mo au
    plus chacune en base64, 8000 px au plus de côté ; le contenu doit correspondre au type annoncé. Charte graphique :
    l'IA n'emploie que ses couleurs et polices (plus blanc, noir, gris neutres). Capture d'écran : l'IA en reproduit la
    structure ; les images de la capture ne sont pas des médias utilisables. Une requête avec images passe au modèle
    page et coûte 10 crédits.
  - **Relecture visuelle** (bouton de l'éditeur) : retouche dont la demande commence par « Relecture visuelle : », avec
    2 captures JPEG du rendu actuel de la section (ordinateur 1280 px, puis mobile 390 px). L'IA corrige les défauts
    visibles (contraste, espacements, alignements, textes coupés, mobile via `mobile.*`) par des opérations, sans toucher
    aux textes, liaisons et médias. Cas P3 de l'évaluation (`Eval/fixtures/p3-*.jpg`).
  - **Médiathèque, propositions de contenu et limites** (07/10/2026, `LandingAiContentContext`,
    `LandingAiContentProposals`) :
    - Tous les modes reçoivent les 40 derniers médias de la médiathèque (titre, type, clé ou adresse), autorisés dans
      la composition : l'administrateur peut désigner un média par son titre (« mets la photo de l'atelier »).
    - Retouche avec `dataType` (identifiant de la donnée affichée) pour Presentation, PresentationGroup,
      BaniereStatique et Video : l'IA reçoit les champs modifiables de cette donnée (et, pour un groupe, ses
      présentations dans l'ordre) et peut **proposer**, sans rien écrire : `contentChanges: [{ resource, id, fields }]`
      (corps prêt pour `PATCH /api/{resource}/{id}?locale=`) et `groupChanges: [{ groupId, add: [{ fields, after }],
      remove: [ids], order: [ids] | null }]` (routes du groupe : supprimer, ajouter avec `after` (null = fin), puis
      `order` = présentations gardées dans le nouvel ordre, les nouvelles placées par `after`). Seules la donnée de la
      section et ses présentations peuvent être visées ; les valeurs passent `LandingContentEditor::validate` (mêmes
      règles que PATCH) et une proposition refusée est renvoyée au modèle comme une composition invalide. Les médias
      restent sous la forme reçue (clé ou adresse). L'éditeur les montre, l'administrateur valide, les routes
      (journalisées) écrivent.
    - Tous les modes : `limits: [{ request, reason, howTo }]` (6 au plus, texte brut) : parties de la demande que
      l'assistant ne peut pas faire, pourquoi et comment l'administrateur peut les faire (guide dans le prompt
      système : structure du site, médiathèque, fiche entreprise, données des autres sections, autres modules,
      annulation). Une entrée sans `howTo` est ignorée. Les chemins de l'éditeur sont cités tels quels depuis
      `ia-libelles-editeur.md`, synchronisé avec les autres fichiers (voir plus haut) ; il fait partie des consignes
      en cache.
    - Réponse : `contentChanges`, `groupChanges` et `limits` toujours présents (tableaux vides par défaut).
  - **Avant tout appel à l'IA** (30/09/2026) : en retouche, la composition reçue est limitée à 200 Ko (400) et doit
    déjà passer le contrat, les types de la famille et les médias (422 `Composition invalide`, sans crédit ni limite
    par minute consommés). Une composition invalide faisait échouer les 3 essais, payés, avec les crédits libérés.
  - **HTML des textes** (toutes les chaînes de la composition : textes, traductions, légendes d'images, titres de
    liens…) : liste blanche identique à RichText du frontend (`RichTextPolicy`, grammaire stricte : tout ce qui
    ressemble à une balise et n'a pas exactement une forme permise est refusé) : p, div, span, strong, b, em, i, u,
    s, br, hr, ul, ol, li, blockquote, small, sub, sup, h2 à h6 ; aucun attribut sauf `style` (font-style, font-weight,
    text-decoration, color). Depuis le 06/10/2026, liens `<a href="…">` avec `href` pour seul attribut et une adresse
    sûre (https, http, mailto, tel, /chemin, #ancre ; lue entités décodées, sans espace ni caractère de contrôle) ;
    `target` et `rel` sont posés par le frontend à l'affichage. Refus 422 au PUT des réglages. L'IA n'ajoute jamais de
    lien : une adresse absente de la composition de départ est renvoyée au modèle (`addedLinks`), les liens posés par
    l'administrateur sont conservés.
  - **Corps trop volumineux** : 413 `{ error, message }` (Symfony au-delà de ~26 Mo, nginx au-delà de 200 Mo). Image :
    5 000 000 caractères base64 au plus.
  - **Une tâche de fond à la fois par utilisateur** : une nouvelle demande page ou images pendant qu'une tâche du même
    utilisateur est en attente ou en cours reçoit 409 `Tâche en cours` avec `jobId`, `status` et l'en-tête `Location`
    de la tâche existante (aucun crédit consommé).
  - **Erreurs passagères de l'API** (429, 529, 5xx) : jusqu'à 2 nouveaux essais du même appel (délai `Retry-After`,
    sinon 2 puis 4 s), dans le délai total ; les autres erreurs donnent 502 aussitôt.
  - **Modèles** : `LANDING_AI_MODEL_EDIT` / `LANDING_AI_MODEL_PAGE` / `LANDING_AI_MODEL_IMAGES` ; un modèle qui refuse l'appel d'outil forcé doit
    figurer dans `LandingAiPromptBuilder::MODELS_WITHOUT_FORCED_TOOL` (Opus 5.5, Sonnet 5.5, Fable 5.1, Mythos 5.1).
  - **Plafond d'échecs** : 20 demandes échouées après un appel réel à l'IA, par site et sur 24 h glissantes ; au-delà,
    429 `Trop d'échecs` avec `Retry-After` (`LandingAiQuotaService::FAILED_REQUESTS_PER_DAY`). Les échecs libèrent
    les crédits mais les appels sont payés : sans ce plafond, seule la limite par minute les bornait.
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
  `LANDING_AI_MODEL_PAGE` (défaut `claude-opus-5-5` : mode page, avec ou sans images), `LANDING_AI_MODEL_IMAGES`
  (retouches et créations avec images, relecture visuelle comprise ; vide = le modèle du mode page).
- Tables par tenant : `ai_usage` (historique, supprimé après 90 jours, jamais de composition ni de réponse du modèle),
  `ai_credit_setting` et `ai_job` (tâches de fond) — `scripts/migrate_all_v2_landing_ai.sh` / migrations
  `Version20260928200000` et `Version20260929150000`.
- Variables de la synchronisation : `FRONTEND_CONFIG_URL`, `DEPLOY_SYNC_TOKEN`.

### Réglages de coût et de qualité (effort, cache)

Variables facultatives (`LandingAiTuning`) ; vides, l'API applique l'effort par défaut du modèle (Sonnet 5 : `high` ;
Opus 5.5 : `medium`) et le cache de 5 minutes. Valeurs d'effort : `low`, `medium`, `high`, `xhigh`, `max`.

| Variable | Demandes concernées |
|---|---|
| `LANDING_AI_EFFORT_EDIT` | retouche sans image |
| `LANDING_AI_EFFORT_CREATE` | création sans image |
| `LANDING_AI_EFFORT_PAGE` | mode page |
| `LANDING_AI_EFFORT_IMAGES` | retouche ou création avec images (relecture visuelle) |
| `LANDING_AI_CACHE_TTL_PAGE` | `1h` : cache d'une heure du contexte fixe des demandes du modèle page (page, images) |

**Mesures du 30/09/2026** (`app:landingpage-ai:eval`, tenant `arkanoa-media`, un passage par réglage ; toutes les
vérifications automatiques réussies à chaque niveau ; la qualité visuelle n'est pas mesurée) :

| Nature | Effort | Essais | Jetons de sortie | Durée | Coût estimé |
|---|---|---|---|---|---|
| Retouche (Sonnet 5, 10 cas) | `high` (défaut) / `medium` / `low` | 1 / 1 / 1 | 331 / 313 / 300 | 3,9 / 3,7 / 4,0 s | 0,034 $ aux trois niveaux |
| Création (Sonnet 5, 7 cas) | `high` (défaut) | 1,14 | 3 166 | 23,5 s | 0,065 $ |
| | `medium` | 1,0 | 2 092 | 16,3 s | 0,050 $ |
| | `low` | 1,0 | 2 117 | 15,4 s | 0,050 $ |
| Page P1, 4 sections (Opus 5.5) | `low` / `medium` (défaut) / `high` | 1 / 1 / 1 | 3 537 / 7 163 / 10 497 | 28 / 58 / 84 s | 0,45 / 0,52 / 0,59 $ cache froid ; 0,11 / 0,18 / 0,25 $ cache chaud |
| Relecture visuelle P3 (Opus 5.5) | `low` / `medium` (défaut) / `high` | 1 / 1 / 1 | 539 / 1 000 / 1 293 | 7 / 11 / 14 s | 0,16 à 0,17 $ cache froid |

- **Proposition** : `LANDING_AI_EFFORT_CREATE=medium` (coût −23 %, durée −30 %, mêmes vérifications réussies) ; le
  reste sur le défaut du modèle. En retouche, l'effort ne change rien de mesurable (le coût vient des jetons d'entrée).
  En page, `low` divise la durée par deux mais a choisi des familles moins adaptées sur P1 (services rendus par un
  groupe de présentations) ; l'économie est faible, l'écriture du cache dominant le coût.
- **Cache d'une heure** : accepté par l'API. Contexte fixe du mode page : ~70 600 jetons ; écriture 0,35 $ (5 min) ou
  0,56 $ (1 h), lecture 0,014 $. Le cache est commun à tous les sites. Le cache d'une heure devient rentable quand
  plus de 40 % environ des demandes du modèle page arrivent entre 5 minutes et 1 heure après la précédente ; en
  dessous, il coûte 0,21 $ de plus par demande. Au 30/09/2026 : 3 demandes de page au total, donc 5 minutes.
  À réévaluer avec `ai_usage` (`created_at`, `mode`, `model`) quand l'usage sera régulier.
- **Comparaison visuelle du 30/09/2026** (banc du frontend, 7 cas de création, données d'arkanoa-media) : `medium` est
  plus sobre, pas plus pauvre (C5 : les 11 blocs de plus en `high` étaient un onglet aux textes inventés ; C2 : `medium`
  meilleur). Seul C6 omettait le logo et l'accroche de l'entreprise : le prompt de création demande désormais de lier
  les données de l'entreprise que la famille permet (`sectionFields`, `boundTools`) et fixe le repli d'une url liée à
  « # ». C6 rejoué 4 fois en `medium` : logo et accroche liés 3 fois sur 4 avant la règle du repli, 2 sur 2 après ;
  passage complet ensuite sans régression (edit 10/10, create 7/7, page 2/2, relecture 1/1).
- **Mesures du 01/10/2026** (site `demo`) :
  - Retouche : le modèle d'origine de la section était envoyé en exemple, en double de la composition. Avec 2 exemples
    (dont l'origine) / 1 (sans l'origine) / 0 : entrée 12 510 / 7 823 / 5 397 jetons, coût 0,034 / 0,024 / 0,020 $,
    mêmes vérifications réussies (10 cas, plus R11 qui ajoute un bloc). **Adopté : 0 exemple** (−41 %) ;
    `LANDING_AI_EDIT_EXAMPLES=1` ou `2` pour en remettre.
  - Mode page et relecture visuelle sur Claude Sonnet 5.5 au lieu d'Opus 5.5 (`LANDING_AI_MODEL_PAGE=claude-sonnet-5-5`) :
    page 0,16 $ au lieu de 0,31 à 0,44 $, P1 en 45 s au lieu de 58 à 140 s ; relecture 0,083 $ au lieu de 0,169 $ ;
    toutes les vérifications réussies, mais P1 n'a pas placé le logo fourni
    (`comparaison-page-opus55-sonnet55-20261001.json`).
  - Verdict du frontend (banc, 1440 et 390 px, 3 cas, un essai par modèle) : en **mode page**, Sonnet 5.5 est
    acceptable (plus sobre, pas plus pauvre) après deux consignes, ajoutées au prompt le jour même : tout média fourni
    est placé (il passe avant la donnée équivalente de l'entreprise, sinon un avertissement dit pourquoi) ; pas de
    `minHeightVh` sans contenu centré (conteneur racine de même hauteur, `valign` center ou end) ni sans hauteur
    mobile réduite (`mobileMinHeightVh`, `mobile.minHeight = 0`) ; en page, une ancre par section (« accueil » pour
    le héros). P1 rejoué avec ces consignes : logo placé, hauteur conforme et ancres posées sur les deux modèles
    (`comparaison-page-p1-consignes-20261001.json`) ; la vérification de P1 exige désormais le logo et la hauteur.
    En **relecture visuelle**, Sonnet 5.5 est un peu plus pauvre (3 défauts corrigés sur 4) : **garder Opus 5.5**.
    `LANDING_AI_MODEL_PAGE` réglait les deux à la fois : `LANDING_AI_MODEL_IMAGES` les sépare. Bascule du mode page
    seul : `LANDING_AI_MODEL_PAGE=claude-sonnet-5-5` et `LANDING_AI_MODEL_IMAGES=claude-opus-5-5` dans `.env`, puis
    redémarrage du worker de l'assistant.
  - Second verdict du frontend sur P1 régénéré : **mode page validé sur Sonnet 5.5**. Défaut grave relevé sur la
    variante Opus 5.5 : ses containers n'avaient pas de `background`, que le moteur rend en blanc opaque (titre blanc
    sur cadre blanc dans un héros bleu nuit), sans qu'aucune vérification ne le voie. Corrections du jour : garde-fou
    (voir « Réparation des sorties »), vérification V7 du contraste sur le fond réel, et trois consignes (liaison par
    défaut, texte écrit à la main seulement si la donnée contredit la demande, avec un avertissement ; contraste
    4,5:1, pas de couleur claire en texte sur fond clair ; chaque container écrit son `background`). P1 rejoué sur
    Sonnet 5.5 : un seul essai, 32 blocs, V7 réussie, héros de nouveau lié à la bannière, 0,27 $ cache froid
    (`comparaison-page-p1-sonnet55-fonds-20261001.json`). **Adopté le 01/10/2026** : `.env` porte
    `LANDING_AI_MODEL_PAGE=claude-sonnet-5-5` et `LANDING_AI_MODEL_IMAGES=claude-opus-5-5` (retour arrière : retirer
    ces deux lignes et redémarrer le worker de l'assistant) ; P2 rejoué sous ce réglage : un essai, V7 réussie.
- **Fond des blocs** : le moteur de rendu ne dessine `background` que pour les containers, boutons et badges, en
  blanc opaque s'il est absent ; un titre, un texte, une image… ne dessinent jamais de fond (frontend :
  `blockBackground`, `normalizeCanvas`). Un container produit par l'IA sans `background` reçoit « transparent », sans
  nouvel essai (`LandingAiOutputRepair::transparentContainers`). Un bouton ou un badge sans `background` est renvoyé
  au modèle : la couleur ne se devine pas (`LandingAiCompositionChecker::missingBackgrounds`). En retouche, seuls les
  blocs ajoutés par l'IA sont concernés ; ceux de départ ne sont ni complétés ni refusés.
- **V7, contraste** (`Eval/LandingAiContrastCheck`, création et page) : chaque titre, texte, bouton ou badge est mesuré
  sur son fond réel : pour un bouton ou un badge, le sien (blanc opaque s'il est absent) ; pour un titre ou un texte,
  le premier container parent qui dessine un fond opaque, puis la section. Seuils : 4,5:1, ou 3:1 à partir de 24 px. Fond image, vidéo, dégradé ou lié à une donnée : non mesuré.
- **V8, boutons et badges `fitContent` décalés** (`Eval/LandingAiAlignCheck`, création et page) : dans un container
  en pile, un bloc en `fitContent` se place selon son propre `align`, les autres selon celui du container. V8 signale
  un bouton ou un badge `fitContent` dont l'`align` diffère de celui de sa pile alors que les textes voisins la
  suivent (bouton centré sous un titre et un texte à gauche). C'est un signalement à regarder, pas un refus : un
  bouton, une icône ou une flèche décalés peuvent être voulus (`group-type-k`, `group-type-s`, `marque-type-d`,
  `banner-type-l`, `carousel-offres`) ; `group-type-b` et `-m` étaient des défauts, corrigés par le frontend.
  `align` absent : « left ». Mobile non mesuré, la règle y est autre : une pile avec `mobile.align` center ou right
  place tous ses enfants ainsi ; le `mobile.align` d'un bloc ne change que l'alignement de son texte.
- **Consignes du 3e verdict du frontend (01/10/2026)** : un bloc `fitContent` prend l'`align` de son container ; un
  défaut de contraste se corrige par la couleur, jamais en retirant un contenu lié (les prix des services avaient
  disparu) ; quand une donnée de remplacement est affichée, les titres écrits à la main décrivent le contenu réel
  (« Nos réalisations », pas « Témoignages ») ; en page, une famille sans donnée listée a quand même un contenu
  (services, coordonnées) et n'est pas remplacée par une autre famille. P1 rejoué sous le réglage de production :
  un essai, V1 à V8 réussies, prix liés, 0,11 $ avec le cache chaud (`comparaison-page-p1-sonnet55-final-20261001.json`).
- **Seconds essais sur Sonnet 5.5** (P1, 01/10/2026) : 3 essais sur 6 ont demandé une seconde génération complète
  (0,36 $ au lieu de 0,27 $ à cache froid), pour `fontFamily: null`, `dataType` à null sur une famille qui a des
  données, puis `fontFamily` sur un bloc `form`. Depuis le 02/10/2026, `LandingAiOutputRepair::dropRefusedKeys`
  retire sans nouvel essai, au premier niveau de la section et des blocs, une propriété à null que le contrat refuse
  (le moteur de rendu la traite comme absente) et une propriété de style de texte (`STYLE_ONLY_PROPERTIES`) posée sur
  un type de bloc qui ne l'utilise pas ; jamais dans `mobile`, où null a un sens. Toute autre propriété refusée reste
  renvoyée au modèle. Pour le `dataType`, consigne : une famille qui a des données reçoit toujours une donnée de sa
  liste, la plus proche à défaut, avec un titre fidèle au contenu affiché et un avertissement.
- **Essai réel du 02/10/2026** (page « Atelier Verde » sur `demo`, Sonnet 5.5, un essai, 87 s, 0,32 $ à cache froid) :
  résultat jugé bon, trois défauts, chacun corrigé par une consigne et mesuré par **V9**
  (`Eval/LandingAiLayoutCheck`, création et page) quand c'est mesurable :
  - prix des services absents : une liste de services lie `item.subtitle` ET `item.price`, chacun dans un bloc text
    avec `hideEmpty` (sur `demo`, le prix est dans `item.subtitle`) ; V9 signale une liste `services` sans l'une des deux ;
  - images liées rognées : contenu inconnu, donc `objectFit: contain` ou un `aspectRatio` large (16/9, 16/10), « cover »
    pour une photo fournie ou un fond ; non mesuré (11 modèles du catalogue sur 19 sont en « cover » à dessein) ;
  - formulaire de contact en double cadre : le bloc form dessine déjà sa carte, son parent direct est un container
    transparent sans marge interne, bordure ni ombre ; V9 le signale.
  Sur le catalogue, V9 relève `service-liste` (sans `item.price`) et `service-sombre` (sans `item.subtitle`).
- **Effets visuels** : le prompt n'en disait rien jusqu'au 02/10/2026. Consigne : quand la demande ne dit rien du style
  ou veut un rendu premium, vivant ou moderne, utiliser avec mesure `animation` et `animationDelay`, `repeat.stagger`,
  `hover`, `shadow`, `textGradient`, `bgGradient` ; style sobre demandé : rester sobre. Cas **P4** du jeu d'essai (page
  premium et vivante, sans image) : au moins trois sortes d'effets, dont `animation` et `hover`.
- **Coût estimé** (`LandingAiPricing`, prix par modèle relevés le 30/09/2026) : calculé d'après les jetons de chaque
  demande, écriture du cache comprise (`ai_usage.cache_write_tokens`, depuis le 02/10/2026 ; 0 sur les demandes plus
  anciennes, dont le coût est alors un peu sous-estimé). Dans l'administration (« Assistant IA : historique »), la
  colonne « Coût estimé ($ US) » et le total du mois du site ne sont montrés qu'au super administrateur ; l'API du
  frontend ne l'expose pas. Repères du 02/10/2026 : retouche 0,02 $, création 0,05 $, page 0,10 $ (cache chaud) à
  0,28 $ (cache froid, un essai) et 0,36 $ (deux essais), relecture visuelle 0,17 $ (Opus 5.5).
- **Média de repli inventé sur un bloc lié** (`LandingAiOutputRepair::dropInventedBoundMedia`, 02/10/2026) : sur P4, le
  premier essai a été refusé pour trois images liées (`item.imageUrl`, `logoUrl`) portant en plus une URL d'exemple.
  Sur une image, une vidéo ou une icône dont le média est lié, une `url` absente de la liste autorisée est retirée
  sans nouvel essai : la donnée fournit le média. Sur un bloc non lié, un média inventé reste renvoyé au modèle.
- **V7 sur le catalogue** (02/10/2026) : 16 modèles sur 87 sous le seuil. Écarts connus et voulus, parce qu'ils
  reproduisent un ancien composant : `presentation-type-d` et `-e`, `group-type-g`, `-k`, `-q`, `contact-type-b`,
  `footer-type-a`, `video-type-a`, et les boutons blancs sur `#007bff` des bannières ; `service-cartes`,
  `service-liste` et `carousel-offres` sont corrigés par le frontend. D'où la consigne : ne pas reprendre les couleurs
  des modèles de référence, sauf si le site n'a encore aucune couleur. Barre superposée (`overlayTop`) : V7 la mesure
  une fois la page défilée (`scrollColor`, sinon la couleur du bloc, sur `scrollBackground` opaque à 80 % au moins)
  et, en mode page, au-dessus de la première section ; sinon elle n'est pas mesurée.
- **Emploi des couleurs de la palette** (02/10/2026) : C2 avait pris pour fond de carte `#3E4A6B`, que le site
  n'emploie que pour du texte, d'où 4,3:1 avec des textes pourtant faits pour les fonds nuit. La demande indique
  désormais, sous la palette, quelles couleurs le site emploie comme fonds et lesquelles comme textes
  (`CompositionInspector::colorRoles`, `LandingAiSiteContext::palette`). Consigne : un fond de carte est une couleur
  déjà employée comme fond ; contraste insuffisant, on corrige d'abord le fond. C2 rejoué deux fois : V7 réussie.
  V7 reste une mesure du jeu d'essai, jamais un refus en production.
- **Scène au défilement** (container `layout: scroll`, annoncée par le frontend le 02/10/2026 : premier enfant = bloc
  video qui avance avec le défilement, autres enfants = étapes avec `stepAt`, `scrollLength` sur le container). Le
  contrat (schéma synchronisé) la valide ; côté assistant :
  - la consigne (une scène seulement si un fichier vidéo est fourni ou lié, jamais YouTube ni Vimeo, une par page, 3 à
    5 étapes, section pleine largeur sans marge) n'est envoyée que si le contrat actif connaît le layout
    (`LandingAiPromptBuilder::supportsScrollScene`), pour qu'un retour à une version antérieure reste sûr ;
  - une scène produite par l'IA sans bloc video en premier enfant, sans fichier, avec YouTube ou Vimeo, ou en double
    dans la page, ou avec deux blocs video, est renvoyée au modèle (`LandingAiCompositionChecker::sceneErrors`) ;
  - la consigne reprend les précisions du frontend : chaque enfant direct de la scène est une étape, donc titre, texte
    et bouton d'une étape sont regroupés dans une carte ; section `fullWidth` sans `contentWidth`, `rootPadding` 0,
    `rootGap` 0, sans `bgVideo` ; `stepAt` et `scrollLength` facultatifs ; pas de `repeat` sur la scène ;
  - (V7 : voir plus bas la mesure des étapes sur la vidéo.)
  - la liste des données de la famille Video dit pour chacune « fichier vidéo », « vidéo YouTube ou Vimeo » ou
    « aucune vidéo » (`LandingAiDataSources::videoKind`) : sans cela, le modèle ne pouvait pas savoir si une scène
    était possible ;
  - cas **C9** du jeu d'essai (scène en quatre étapes avec la vidéo du site `demo`). Contrat 6e370ac307a8cc58 tiré le
    02/10/2026 ; C9 joué deux fois : un essai, une scène, vidéo liée en premier enfant, 4 étapes en cartes, section
    pleine largeur sans marge, 0,12 $ (`comparaison-scene-defilement-20261002.json`).
  - verdict du frontend sur C9 (02/10/2026) : la scène fonctionne ; consignes ajoutées : `size` écrit sur chaque titre
    (26 à 32) et texte (15 à 17) d'une étape, `stepAt` absent sauf si la demande donne les moments et jamais plus de
    85 pour la dernière étape, carte d'étape sombre opaque à 0,75 ou plus (0,70 au minimum) ;
  - `LandingAiOutputRepair::dropLateSteps` : si une étape dépasse 85, les `stepAt` de la scène sont retirés sans
    nouvel essai (étapes réparties) ;
  - V7 mesure le texte d'une étape sur sa carte composée sur la pire image possible de la vidéo (blanc ou noir), et
    signale une carte à moins de 70 % d'opacité ou un texte posé directement sur la vidéo.
- **Boutique réglable** (08/10/2026, catalogue version 2 : familles `app: boutique`, `systemPages`) : les `componentKey`
  de la boutique (`ProductPage`, `Checkout`, `CartPage`…) passent par les mêmes routes et le même quota, sans rien
  déclarer (catalogue synchronisé). Consigne `COMMERCE_RULE` (`LandingAiPromptBuilder`, ajoutée quand le contrat
  connaît la propriété `mode` des containers) : les blocs du commerce lisent les données de la page, l'IA ne règle
  que leur apparence et n'invente jamais une donnée de produit. Garde-fou en retouche
  (`LandingAiCompositionChecker::lostRequiredBlocks`) : un bloc d'un type exigé par une page système (`stripePayment`,
  `cartLines`, `loginForm`…) ou un container portant `mode`, présent au départ, doit rester (même type, même mode),
  **même si la demande le demande** : la proposition est renvoyée au modèle. Les créations et le mode page ne sont pas
  rattachés à une page système : c'est le PUT des réglages (`docs/boutique.md`) qui exige les blocs obligatoires.
- **Prompt de vidéo** (`POST /api/landingpage-ai/video-prompt`, 02/10/2026 ; `WriteLandingVideoPromptUseCase`,
  `LandingAiVideoPromptWriter`) : l'administrateur décrit en français la vidéo d'une scène au défilement, la réponse
  est un prompt en anglais pour un outil de génération de vidéo. Entrée `{ prompt (2 000 car.), format: landscape |
  portrait | both, duration: 5 | 8 | 10, textSide: left | right | center, background: "#rrggbb", locale }` ; sortie
  `{ prompt, promptMobile (si both), steps: [{ at, title, text }], notes: [], credits, usage }`. 1 crédit, synchrone,
  modèle de la retouche, limite par minute commune à l'assistant ; un échec libère le crédit. Contraintes écrites
  dans le prompt : un seul plan, fond uni de la couleur donnée, poses immobiles (une par étape), mouvement propre en
  marche arrière, aucun texte lisible, côté du texte laissé vide, durée et format en toutes lettres. Les `at` sont
  triés et ramenés entre 0 et 85 sans nouvel essai. Mesure : 0,02 $ et 8 à 13 s par demande. Historique : mode `video`.
- **Version téléphone d'une scène** : un second bloc video dans un container `scroll` est la vidéo verticale (9:16),
  affichée à la place de la première sur écran étroit ; deux blocs video au plus, chacun avec un fichier. L'assistant
  ne l'ajoute que si deux vidéos sont fournies.
- **Déplacer sans supprimer** (03/10/2026) : sur arkanoa-media, « mets la vidéo à droite du titre » a donné une
  proposition faite d'un seul `remove` du container du héros, qui vidait la section dans l'aperçu (rien n'a été
  enregistré). Le modèle n'avait que `remove` puis `add` pour déplacer un bloc. Depuis : opération `move`
  (`{op: move, id, parentId?, after?}`, le bloc garde son contenu et ses descendants), consigne « déplacer = move,
  supprimer seulement si la demande le demande, demande déjà satisfaite = aucune opération », et garde-fou : une
  retouche qui fait disparaître des blocs alors que la demande ne parle pas de supprimer
  (`LandingAiComposer::asksRemoval`) est renvoyée au modèle. Rejoué sur la même section de `demo` : un `move`, un
  essai, 3 s, aucun bloc perdu.
- **Réparation des sorties** (`LandingAiOutputRepair`) : le modèle écrit parfois `"dividerWidth100": 100` en double de
  `"dividerWidth": 100` (8 refus sur 9 en création le 30/09). Une clé inconnue « propriété connue + nombre » dont la
  valeur est ce nombre est retirée avant la vérification, sans nouvel essai ; toute autre clé inconnue reste une erreur.
- **Comparer deux variantes** (prompt, effort, modèle) : un passage par variante, puis
  `php bin/console app:landingpage-ai:compare <sujet> avant=<rapport> apres=<rapport> [--objet="…"]` écrit
  `var/landing-ai-eval/comparaison-<sujet>-<AAAAMMJJ>.json`, le format que le banc du frontend affiche côte à côte
  (`/dev/reglable-compare?compare=<fichier>`) : cas C1…Cn, `componentKey`, `prompt`, puis une clé par variante avec
  `composition` (ou `sections` en page), `dataType`, `summary`, `warnings`, `blocs`, `essais`, `jetonsSortie`,
  `dureeMs`. Les noms de variantes sont libres.
- **Site de test `demo`** (`demo.arkanoa-media.com`, base `db_demo`, copie d'`arkanoa-media` du 30/09/2026 avec 4 prestations
  fictives et 1 000 crédits par mois) : site par défaut de l'évaluation (`--tenant=demo`) et des essais de l'éditeur. On
  peut y écrire, casser et réinitialiser à volonté ; jamais d'évaluation sur un site client.
- La réponse (`usage`) et le rapport d'évaluation comptent à part les écritures de cache (`cacheWriteTokens`, comprises
  dans `inputTokens`) ; le rapport estime le coût en dollars (prix dans `LandingAiEvalCommand::PRICES`, à tenir à jour).

