# Module Landing Page

Réglages des sites vitrines (onglets, sections, navbar, footer), validation des compositions « réglables », assistant
IA de l'éditeur, synchronisation de la configuration publiée par le frontend. Contexte : `docs/architecture.md`.
Détail de l'assistant et de la synchronisation (modes, quota, délais, procédures) : **`config/landingpage/README.md`**.
Côté frontend : `src/components/LandingPage/README.md` (dépôt `Izzipro_next`).

## Endpoints

Routes et rôles : `docs/endpoints.md`, section « Landing Page » ; données des sections : « Contenus des sites ».

| Besoin | Endpoint | Accès |
|---|---|---|
| Lire / enregistrer les réglages du site | `GET` / `PUT /api/landingpage-settings` | public / `ROLE_ADMIN` |
| Familles de sections et source de leurs données | `GET /api/components-config` | public |
| Assistant IA (retouche, création, page, images) | `POST /api/landingpage-ai/compose` → 200, ou 202 `{ jobId }` | `ROLE_ADMIN` |
| Assistant IA : prompt d'une vidéo pour une scène au défilement (1 crédit, synchrone) | `POST /api/landingpage-ai/video-prompt` | `ROLE_ADMIN` |
| Résultat d'une tâche de fond | `GET /api/landingpage-ai/jobs/{jobId}` (même tenant, 1 h) | `ROLE_ADMIN` |
| Crédits et historique | `GET /api/landingpage-ai/usage` | `ROLE_ADMIN` |
| Synchronisation de la configuration | `GET /api/landingpage-config/status`, `POST …/sync`, `POST …/rollback` | `ROLE_SUPER_ADMIN` ou en-tête `X-Deploy-Token` |
| Modifier le contenu d'une section (champs envoyés seulement) | `PATCH /api/{presentations, presentation-groups, baniere-statiques, bannieres, videos, service-offers}/{id}?locale=` | `ROLE_ADMIN` |
| Téléverser une image ou une vidéo dans la médiathèque | `POST /api/media` (multipart) → 201 `{ id, key, url, type, mimeType, size, title, scrollStatus }` | `ROLE_ADMIN` |

Le PUT enregistre le document tel quel et le GET le restitue à l'identique : seuls sont contrôlés les compositions
(`reglableConfig`, contrat et HTML des textes) et le nom facultatif d'une section (`tabs[].sections[].name` : texte de
60 caractères au plus, sans `<` ni `>` ; absent, `null` ou vide = pas de nom). Tout autre champ ajouté par le frontend
au niveau d'un onglet ou d'une section est conservé sans contrôle.

## Modifier les contenus depuis l'éditeur

Depuis le 06/10/2026, l'éditeur modifie les contenus jusque-là gérés dans EasyAdmin (`LandingContentController`,
`PatchLandingContentUseCase`, `Services/LandingContentService/` : `LandingContentSpec` pour la liste blanche,
`LandingContentEditor` pour le contrôle et l'écriture). Les GET, POST, PUT et DELETE existants ne changent pas.

| Ressource | Champs modifiables (traduits en gras) |
|---|---|
| `presentations` | **`titre`**, **`texte`**, **`texteBouton`**, **`lienBouton`**, `image` |
| `presentation-groups` | **`titre`**, `texte` |
| `baniere-statiques` | **`titre`**, **`texte`**, **`texteBouton`**, `imageDeFond`, `colorBackground` |
| `bannieres` | **`titre`**, **`texte`**, `imageDeFond` |
| `videos` | **`titre`**, **`description`**, **`texteBouton`**, **`lienBouton`**, `imageDeFond`, `lienVideo` |
| `service-offers` | **`titre`**, **`titreCommentaire`**, **`descriptions`**, `logo`, `photoService` |

- Corps : objet JSON des seuls champs à changer ; valeur texte, ou `null` / `""` pour vider (sauf `titre`, obligatoire).
  Champ inconnu ou refusé : 422 `{ error, errors: [{ path, message }] }`, et rien n'est écrit.
- Textes : liste blanche HTML des compositions réglables (`RichTextPolicy`), liens compris ; titre et bouton : 255
  caractères au plus.

**Liens dans les textes** (06/10/2026, la sécurité d'abord) : `<a href="…">…</a>` est permis dans tous les textes
(compositions et contenus), avec `href` pour SEUL attribut, entre guillemets. L'adresse est lue comme le navigateur la
lit (entités HTML décodées) et doit être `https://…`, `http://…`, `mailto:…`, `tel:…`, un chemin du site (`/…`, pas
`//…`) ou une ancre (`#…`), sans espace ni caractère de contrôle, 2 000 caractères au plus : `javascript:`, `data:`,
`vbscript:` et leurs déguisements (`&#106;avascript:`, tabulation codée) sont refusés. `target`, `rel`, `style` et
tout autre attribut sur `<a>` sont refusés : le frontend pose `target` et `rel="noopener noreferrer"` à l'affichage
et revérifie l'adresse. L'assistant IA n'ajoute jamais de lien (une adresse nouvelle est renvoyée au modèle) mais
conserve ceux de l'administrateur. Même règle d'adresse pour `lienBouton` (`RichTextPolicy::urlProblem`).
- Liens (`lienBouton`) : `https://`, `http://`, `mailto:`, `tel:`, chemin du site (`/…`) ou ancre (`#…`).
- Images et vidéo : clé de la médiathèque du site (64 caractères hexadécimaux, du bon type), enregistrée sous la forme
  `/media/secure/{clé}`, ou URL `https://`. Les DTO de lecture rendent ces valeurs en URL complète
  (`MediaUrlResolver::joinStored`) ; un nom de fichier téléversé par l'administration reste servi comme avant.
- `colorBackground` : `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb(…)`, `rgba(…)` ou `transparent`.
- Langue (`?locale=`, défaut `fr`, mêmes règles qu'en lecture) : un champ traduit est écrit dans la traduction de cette
  langue, créée au besoin (son titre part du titre de base) ; en `fr`, le champ de base est aussi mis à jour. Les
  champs non traduits sont communs à toutes les langues.
- Réponse 200 : l'objet tel que le GET de la ressource le renvoie dans cette langue ; le cache des GET est invalidé
  (une présentation invalide aussi les groupes). Un objet d'un autre site est dans une autre base : 404, jamais 403.
- `POST /api/media` (`MediaApiController`, `UploadMediaUseCase`, `Services/SharedMedia/SharedMediaStorage`, commun
  avec la médiathèque d'EasyAdmin) : champs `file`, `title` et `prepareForScroll` ; images et vidéos seulement (415
  sinon, contenu contrôlé comme dans l'administration), 100 Mo au plus (413). Le média est privé, servi par
  `/media/secure/{clé}` ; avec `prepareForScroll`, une vidéo est préparée pour le défilement en tâche de fond.
- `PUT /api/entreprise/{id}` : `LegalNotice`, `conditionOfUse`, `privacyPolicy` et `apropos` sont écrits dans la
  traduction de la langue demandée (exactement : jusqu'au 06/10/2026, écrire l'anglais d'un site qui n'avait que le
  français écrasait le français) ; `adress` (texte libre) est commun ; il n'y a pas de champ « slogan ». Ces textes
  ne sont pas contrôlés par la liste blanche HTML.

## Code

| Sujet | Fichiers |
|---|---|
| Réglages | `Controller/LandingPageSettingsController/`, entité `LandingPageSetting` (une ligne JSON par tenant) |
| Validation (422 `{ path, message }`) | `Services/LandingPageSettingsService/ReglableCompositionValidator.php` (JSON Schema opis + règles entre blocs), `RichTextPolicy.php` (HTML permis dans les textes), `ReglableCompositionScanner.php` (toutes les bases) |
| Familles (`components-config`) | `Services/LandingPagesService/ComponentsConfigProvider.php` (source unique : endpoint et assistant) |
| Assistant : moteur | `Services/LandingAiService/` : `LandingAiComposer` (appel, vérifications, 3 essais), `LandingAiPromptBuilder` (prompt système, outils, cache), `LandingAiCatalogue`, `LandingAiDataSources` (valeurs de `dataType`), `CompositionEditApplier` (opérations de retouche), `LandingAiCompositionChecker`, `AnthropicLandingAiClient` |
| Assistant : réglages, réparation | `LandingAiTuning` (effort par nature de demande, durée du cache), `LandingAiOutputRepair` (clés en double du modèle) |
| Assistant : quota, tâches | `LandingAiQuotaService` (réservation atomique, `pg_advisory_xact_lock`), `LandingAiJobService`, `LandingAiComposeRunner` |
| Assistant : cas d'usage, HTTP | `UseCase/LandingAiUseCase/` (`ComposeLandingSection`, `RunLandingAiJob`, `GetLandingAiJob`, `GetLandingAiUsage`), `Controller/LandingAiController/`, DTO `LandingAiComposeInputDto` / `OutputDto` |
| Worker | `Message/LandingAiJobMessage.php`, `MessageHandler/LandingAiJobHandler.php` (transport `landing_ai`) |
| Synchronisation | `Services/LandingConfigService/` (`LandingConfigStore` : versions dans `var/landingpage-config/`, `FrontendConfigFetcher`, `LandingConfigCompatibilityChecker`), `UseCase/LandingConfigUseCase/`, `Controller/LandingConfigController/`, `Repository/LandingConfigSyncRepository.php` |
| Administration | `Controller/Admin/AiUsageCrudController.php` (historique), `AiCreditSettingCrudController.php` (crédits) |
| Évaluation | `Command/LandingAiEvalCommand.php`, `Services/LandingAiService/Eval/` (cas R, C, P ; images dans `Eval/fixtures/`) |

## Tables

| Table | Base | Contenu |
|---|---|---|
| `landing_page_setting` | tenant | configuration JSON du site |
| `ai_usage` | tenant | réservations et historique des crédits (90 jours ; jamais de composition ni de réponse du modèle) ; jetons par demande, d'où le coût estimé montré au super administrateur (`LandingAiPricing`) |
| `ai_credit_setting` | tenant | crédits mensuels (100 par défaut) |
| `ai_job` | tenant | tâches de fond (résultat 1 h, requête effacée au traitement) |
| `landing_config_sync` | maître (`app_v2_db`) | historique des synchronisations |

## Fichiers de configuration partagés

`config/landingpage/` : `landingpage-reglable.schema.json` (contrat), `landingpage-ia-catalogue.json` (familles, outils,
modèles), `ia-assistant-jeu-essai.md` (cas d'évaluation). **Ne pas les modifier à la main** : la version active vient
de la synchronisation (`var/landingpage-config/`) ; ceux du dépôt sont la version de repli (tests, serveur neuf),
recopiés depuis la dernière version synchronisée.

## Tester

- `tests/Functional/LandingPage/` : `LandingAiComposeTest` (client IA simulé : modes, quota, tâches, isolation),
  `LandingConfigSyncTest` (faux frontend), `CompositionEditApplierTest`, `LandingAiEasyAdminTest`.
- Évaluation réelle, sur le site de test `demo` uniquement (défaut) : `app:landingpage-ai:eval [--tenant=demo] [--mode=edit|create|page|all] [--case=R1]` ;
  comparaison de deux passages pour le banc du frontend : `app:landingpage-ai:compare` (voir `config/landingpage/README.md`).
- Compositions de tous les sites : `app:landingpage:check-reglable`.

## Pièges connus

- Règle `access_control` de `/api/landingpage-ai` et `/api/landingpage-config` **avant** la règle publique qui contient `landingpage`.
- Décoder le JSON des compositions **en objets** (`json_decode(…, false)`) : un `{}` décodé en tableau devient `[]` et est refusé par le schéma.
- `ANTHROPIC_API_KEY_LANDING` : clé distincte de celle de Mémoires Vivantes ; jamais dans une réponse ni un journal.
- Claude Opus 5.5 refuse `tool_choice` forcé : `tool_choice: auto` + consigne (`LandingAiPromptBuilder::MODELS_WITHOUT_FORCED_TOOL`).
- Après une modification du prompt ou du modèle : rejouer l'évaluation et comparer le rapport (`var/landing-ai-eval/`).
- Une retouche commence par valider la composition reçue (`LandingAiComposer::editErrors`) : ne jamais appeler l'IA
  sur une entrée invalide (3 essais payés, crédits libérés). Plafond : 20 échecs réels par site et par 24 h.

## Audit du 29/09/2026 : suivi des corrections

Rapport complet transmis au frontend ; corrections par lots (tests à chaque lot).

| Lot | Point | État |
|---|---|---|
| 1 | `ROLE_SUPER_ADMIN` attribuable par un admin de site dans EasyAdmin (pilote la configuration commune) | corrigé le 30/09 (`RoleAssignmentPolicy`) |
| 1 | Échecs de l'IA payés sans crédit ; composition de retouche non validée ni plafonnée | corrigé le 30/09 (validation préalable, 200 Ko, plafond d'échecs) |
| 2 | Demande identique pendant une tâche en cours : payée deux fois | corrigé le 30/09 (409 avec le `jobId` existant) |
| 2 | Aucune reprise sur 429, 529 et 5xx de l'API | corrigé le 30/09 (2 nouveaux essais, `AnthropicApiException`) |
| 2 | `claude-sonnet-5-5` absent de `MODELS_WITHOUT_FORCED_TOOL` | corrigé le 30/09 |
| 3 | Balises HTML des textes non contrôlées côté backend | corrigé le 30/09 (`RichTextPolicy` : liste du frontend, `style` limité) ; liens `<a href>` sûrs permis depuis le 06/10 |
| 3 | 400 au lieu de 413 JSON pour un corps trop volumineux | corrigé le 30/09 (Symfony et nginx) |
| 3 | CORS de `/media/secure` limité à une liste de domaines codée en dur dans nginx | corrigé le 30/09 : `*` sans cookies (la clé est la seule autorisation), valable pour tout nouveau domaine |
| 3 | Jeton JWT sans `tenant_code` accepté sur un site ; synchronisation activée malgré une base illisible ; pas de limite sur `X-Deploy-Token` ; limite des images ; nettoyage des tâches | corrigé le 30/09 |

Côté frontend (29/09) : le schéma corrigé (texte sans `<a>`, `id` des blocs `^[A-Za-z0-9_-]{1,100}$`) arrive par la
synchronisation au prochain déploiement ; les images de la console sont réduites à 2 000 px en JPEG ; la vérification
TLS du relais `/api` est réactivée.

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, service ou changement de contrat : mettre à jour cette fiche et
`config/landingpage/README.md`, puis régénérer `docs/endpoints.md` (commande en tête du fichier).
