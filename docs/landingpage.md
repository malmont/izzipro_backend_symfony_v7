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
| Bibliothèque de modèles de site (propre au site ; boutique : `/api/boutique-site-models`, voir `docs/boutique.md`) | `GET`, `POST /api/landingpage-site-models` ; `GET`, `PUT`, `DELETE …/{id}` | `ROLE_ADMIN` |
| Modifier le contenu d'une section (champs envoyés seulement) | `PATCH /api/{presentations, presentation-groups, baniere-statiques, bannieres, videos, service-offers}/{id}?locale=` | `ROLE_ADMIN` |
| Téléverser une image ou une vidéo dans la médiathèque | `POST /api/media` (multipart) → 201 `{ id, key, url, type, mimeType, size, title, scrollStatus }` | `ROLE_ADMIN` |
| Composer un groupe de présentations | `POST /api/presentation-groups/{id}/presentations`, `DELETE …/presentations/{pid}`, `PUT …/presentations/order` | `ROLE_ADMIN` |
| Journal des écritures, retour en arrière | `GET /api/landingpage-audit`, `GET …/{id}`, `POST …/{id}/restore` | `ROLE_ADMIN` |
| Parcourir, renommer, supprimer les médias | `GET /api/media?type=&page=&limit=&q=` ; `PATCH`, `DELETE /api/media/{id}` | `ROLE_ADMIN` |

Le PUT enregistre le document tel quel et le GET le restitue à l'identique : seuls sont contrôlés les compositions
(`reglableConfig`, contrat et HTML des textes) et le nom facultatif d'une section (`tabs[].sections[].name` : texte de
60 caractères au plus, sans `<` ni `>` ; absent, `null` ou vide = pas de nom). Tout autre champ ajouté par le frontend
au niveau d'un onglet ou d'une section est conservé sans contrôle.

## Groupes de présentations

Depuis le 07/10/2026 (`PresentationGroupEditor`, `ManagePresentationGroupUseCase`) : `POST …/{id}/presentations`
crée une présentation (champs de `PATCH /api/presentations`, `titre` obligatoire, `after` : identifiant après lequel
la placer, ou fin) et l'ajoute au groupe, 60 au plus ; `DELETE …/presentations/{pid}` la retire du groupe et la
supprime si elle n'appartient plus à aucun groupe (sinon seulement détachée) ; `PUT …/presentations/order`
`{ order: [ids] }` (toutes les présentations du groupe, une fois chacune). Réponse : le groupe tel que son GET le
renvoie. L'ordre est gardé dans `presentation_group.presentation_order` (liste JSON, script
`migrate_all_v2_presentation_order.sh`) et suivi par toutes les lectures du groupe ; sans ordre : ordre de la base.

## Journal des écritures

Depuis le 07/10/2026 (`ContentAuditRecorder`, table `content_audit_log` par tenant, script
`migrate_all_v2_content_audit.sh`, 180 jours) : chaque écriture de l'éditeur est inscrite avec l'utilisateur, le
site, la ressource, l'action, la langue, les champs modifiés et l'état avant / après (JSON) : `PATCH` des contenus,
groupes de présentations (ajout, retrait, ordre), `PUT /api/entreprise` (clés envoyées qui ont changé),
`PUT /api/landingpage-settings` (configuration complète), modèles de site (création, modification, suppression),
médiathèque (téléversement, renommage, suppression), et depuis le 08/10/2026 les réglages et modèles de la boutique
réglable (`boutique-settings`, `boutique-site-models` : `docs/boutique.md`). Lecture : `GET /api/landingpage-audit?resource=&resourceId=&page=&limit=`
(résumés, plus récents d'abord) et `GET …/{id}` (avec `before` et `after`). Retour en arrière :
`POST …/{id}/restore` (`ContentAuditRestorer`), journalisé à son tour (`action: restore`, `restoredFrom`) donc
annulable ; refusé (409) si la ressource a changé depuis cette écriture, sauf `?force=1` ; impossible pour un
téléversement, un ajout ou un retrait de présentation, une suppression de média (`restorable: false`). Une
suppression de modèle de site se rétablit en le recréant (nouvel identifiant).

## Fiche entreprise : HTML des textes longs

`LegalNotice`, `conditionOfUse`, `privacyPolicy`, `apropos` passent par `LegalTextPolicy` (07/10/2026) : p, br, h1 à
h6, strong, b, em, i, u, s, small, sub, sup, span, div, hr, blockquote, ul, ol, li, table, thead, tbody, tfoot, tr,
th, td, caption, a ; aucun attribut sauf `href` sur `<a>` (règle de `RichTextPolicy`) et `colspan`, `rowspan`
(nombres) sur `td` et `th`. Refus 422 `{ error, errors: [{ path, message }] }` sans rien écrire. Un texte renvoyé tel
qu'il est déjà enregistré n'est pas contrôlé : un site dont les anciens textes ne passent pas peut encore enregistrer
le reste de sa fiche. Inventaire : `php bin/console app:entreprise:check-legal-texts` (au 07/10/2026 : seuls les 6
textes de Kara & B, avec `className`, `target`, `rel`).

## Formulaire de contact

`POST /api/contacts/create` (ou `POST /api/contacts`), public (07/10/2026 : `ContactApiController::create`,
`CreateContactUseCase`, `ContactCreateInputDto`, `ContactService::createFromForm`). Jusque-là tout envoi était refusé :
`phone` et `subject` étaient exigés et le message attendu sous le nom `Content`.

| Champ | Règle |
|---|---|
| `name` | obligatoire, 2 à 255 caractères |
| `email` | obligatoire, adresse valide, 255 au plus |
| `message` | obligatoire, 10 000 caractères au plus (`Content`, ancien nom, encore accepté) |
| `phone` | facultatif ; sinon 5 à 30 caractères, chiffres, espaces et `+ ( ) . -` |
| `subject` | facultatif, 255 au plus ; absent : « Demande de contact – <nom de l'entreprise> » |
| `industry` | facultatif : `tourisme`, `btp`, `agroalimentaire` ou `autre` (casse ignorée) |
| `companyName`, `jobFunction` | facultatifs, 255 au plus |

- Champ vide ou blanc = absent ; tout autre champ (`acceptPolicy`, `isRead`…) est ignoré.
- 201 : le contact (`ContactOutputDto`, message sous `Content`). 422 :
  `{ "message": "Formulaire incomplet", "errors": [{ "field", "message" }] }`, messages en français, une erreur par champ.
- E-mails : à l'entreprise (secteur, entreprise et fonction compris, réponse directe au visiteur) et confirmation au
  visiteur, par la configuration d'envoi du site. Site sans configuration : l'e-mail à l'entreprise part par le serveur
  de la plateforme (`EmailSenderService::sendPlatformEmail`), la confirmation au visiteur n'est pas envoyée.
- Limite : voir `docs/architecture.md` (10 envois acceptés par 10 minutes, site + IP ; les refus ne comptent pas).
- Colonnes `industry`, `company_name`, `job_function`, `phone` facultatif : `scripts/migrate_all_v2_contact_fields.sh`,
  migration `Version20261007160000`.

## Modèles de site

Depuis le 07/10/2026, l'administrateur enregistre des configurations complètes comme modèles, sans toucher aux réglages
publiés (`LandingSiteModelController`, use cases `LandingSiteModelUseCase/`, `Services/LandingSiteModelService/`,
entité `LandingSiteModel`, table `landing_site_model` par tenant ; script `scripts/migrate_all_v2_landing_site_models.sh`,
migration `Version20261007100000`). Depuis le 08/10/2026, la route est `/api/{app}-site-models` : `landingpage` (ci-dessous)
ou `boutique` (`docs/boutique.md`), chaque application ayant sa liste (colonne `app`, migration `Version20261008100000`)
et ses règles de contrôle ; le journal distingue `landingpage-site-models` et `boutique-site-models`.

- `GET /api/landingpage-site-models` : résumés `{ id, name, description, createdAt, updatedAt, tabs, sections }`, du plus
  récemment modifié au plus ancien ; `GET …/{id}` : `{ id, name, description, createdAt, updatedAt, configuration }`,
  configuration restituée telle qu'enregistrée (JSON brut : `{}` et `1.0` conservés).
- `POST` `{ name, description?, configuration }` → 201 résumé ; `PUT …/{id}` : champs à changer parmi les trois → 200
  résumé ; `DELETE …/{id}` → 204. Un modèle d'un autre site est dans une autre base : 404.
- `name` : 1 à 80 caractères, sans `<` ni `>` ; `description` : 300 caractères au plus, ou `null`. `configuration` :
  objet, contrôlé comme le corps de `PUT /api/landingpage-settings` (`validateConfiguration` : compositions des
  onglets, navbar, footer et `reglablePresets` selon le schéma synchronisé et `RichTextPolicy`, noms de section).
  Champ inconnu ou refusé : 422 `{ error, errors: [{ path, message }] }`, chemins de la configuration préfixés par
  `configuration.`. Configuration de plus de 2 Mo (`MAX_CONFIGURATION_BYTES`, encodée) : 413. 30 modèles au plus par
  site (`MAX_MODELS`) : 422 au-delà.

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
| `products`, `category`, `homeslider`, `explore-cards` | données de la boutique réglable (08/10/2026) : voir `docs/boutique.md` |

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

**Assistant IA** (07/10/2026) : en retouche, l'assistant peut proposer des modifications de ces contenus
(`contentChanges`) et de la composition d'un groupe (`groupChanges`), contrôlées comme ces routes mais jamais écrites
par lui : l'éditeur les fait valider puis appelle les routes ci-dessous (détail : `config/landingpage/README.md`).
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
- `GET /api/media` (`ListMediaUseCase`, `SharedMediaRepository::searchForEditor`) : images et vidéos du site, liens
  expirés exclus, du plus récent au plus ancien ; `type` (image, video), `page` (dès 1), `limit` (1 à 100, 40 par
  défaut), `q` (titre, contient, sans casse) ; 400 si un paramètre est invalide. Réponse `{ items, total, page, limit }`,
  chaque élément `{ id, key, url, type, mimeType, size, title, createdAt, width, height, duration, scrollStatus,
  visibility }` : `key` est `null` pour un média public (téléversé public dans l'administration), dont `url` est
  l'adresse directe du fichier ; `width` et `height` sont lus dans l'en-tête des images ; `duration` reste `null` (pas
  de ffprobe dans le conteneur du site) ; pas de vignette.
- `PATCH /api/media/{id}` `{ title }` (1 à 255 caractères, sans `<` ni `>`) → 200 l'élément ; `DELETE /api/media/{id}`
  → 204, ou 409 `{ error, usages }` tant que le média sert encore : réglages publiés et modèles de site (chemin dans
  la configuration et nom de l'onglet), contenus de section (images, vidéo). Recherche par la clé (privé) ou le nom de
  fichier (public) : `SharedMediaLibrary`. La suppression depuis EasyAdmin ne fait pas cette vérification.
- `PUT /api/entreprise/{id}` : `LegalNotice`, `conditionOfUse`, `privacyPolicy` et `apropos` sont écrits dans la
  traduction de la langue demandée (exactement : jusqu'au 06/10/2026, écrire l'anglais d'un site qui n'avait que le
  français écrasait le français) ; `adress` (texte libre) est commun ; il n'y a pas de champ « slogan ». Ces textes
  ne sont pas contrôlés par la liste blanche HTML.

## Code

| Sujet | Fichiers |
|---|---|
| Réglages | `Controller/LandingPageSettingsController/`, entité `LandingPageSetting` (une ligne JSON par tenant) |
| Validation (422 `{ path, message }`) | `Services/LandingPageSettingsService/ReglableCompositionValidator.php` (JSON Schema opis + règles entre blocs), `RichTextPolicy.php` (HTML permis dans les textes), `ReglableCompositionScanner.php` (toutes les bases : réglages des landing pages et de la boutique, modèles de site) |
| Familles (`components-config`) | `Services/LandingPagesService/ComponentsConfigProvider.php` (source unique : endpoint et assistant) |
| Assistant : moteur | `Services/LandingAiService/` : `LandingAiComposer` (appel, vérifications, 3 essais), `LandingAiPromptBuilder` (prompt système, outils, cache), `LandingAiCatalogue`, `LandingAiDataSources` (valeurs de `dataType`), `CompositionEditApplier` (opérations de retouche), `LandingAiCompositionChecker`, `AnthropicLandingAiClient`, `LandingAiContentContext` (médiathèque, contenus modifiables de la donnée affichée), `LandingAiContentProposals` (contrôle de `contentChanges`, `groupChanges`, `limits`, jamais appliqués) |
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
modèles ; version 2 : familles `app: boutique`, `systemPages`), `ia-assistant-jeu-essai.md` (cas d'évaluation),
`ia-libelles-editeur.md` (facultatif : libellés de l'éditeur cités par l'assistant). **Ne pas les modifier à la main** :
la version active vient de la synchronisation (`var/landingpage-config/`) ; ceux du dépôt sont la version de repli
(tests, serveur neuf), recopiés depuis la dernière version synchronisée (09/10/2026 : `f254aec13928f7c0`, pages fixes dans le menu, badge du panier enrichi).

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
