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
| Résultat d'une tâche de fond | `GET /api/landingpage-ai/jobs/{jobId}` (même tenant, 1 h) | `ROLE_ADMIN` |
| Crédits et historique | `GET /api/landingpage-ai/usage` | `ROLE_ADMIN` |
| Synchronisation de la configuration | `GET /api/landingpage-config/status`, `POST …/sync`, `POST …/rollback` | `ROLE_SUPER_ADMIN` ou en-tête `X-Deploy-Token` |

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
| `ai_usage` | tenant | réservations et historique des crédits (90 jours ; jamais de composition ni de réponse du modèle) |
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
| 3 | Balises HTML des textes non contrôlées côté backend | corrigé le 30/09 (`RichTextPolicy` : liste du frontend, sans `<a>`, `style` limité) |
| 3 | 400 au lieu de 413 JSON pour un corps trop volumineux | corrigé le 30/09 (Symfony et nginx) |
| 3 | CORS de `/media/secure` limité à une liste de domaines codée en dur dans nginx | corrigé le 30/09 : `*` sans cookies (la clé est la seule autorisation), valable pour tout nouveau domaine |
| 3 | Jeton JWT sans `tenant_code` accepté sur un site ; synchronisation activée malgré une base illisible ; pas de limite sur `X-Deploy-Token` ; limite des images ; nettoyage des tâches | corrigé le 30/09 |

Côté frontend (29/09) : le schéma corrigé (texte sans `<a>`, `id` des blocs `^[A-Za-z0-9_-]{1,100}$`) arrive par la
synchronisation au prochain déploiement ; les images de la console sont réduites à 2 000 px en JPEG ; la vérification
TLS du relais `/api` est réactivée.

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, service ou changement de contrat : mettre à jour cette fiche et
`config/landingpage/README.md`, puis régénérer `docs/endpoints.md` (commande en tête du fichier).
