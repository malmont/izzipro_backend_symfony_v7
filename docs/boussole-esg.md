# Module Boussole ESG

Autodiagnostic ESG d'une entreprise : questionnaire en 4 domaines, scores, recommandations de certifications (avec
subventions), rapport PDF et dépôt de documents justificatifs. Contexte : `docs/architecture.md`.
Code : `src/ESG/` (module autonome). Tenant principal : `boussoleesg` (base `db_boussoleesg`).
Côté frontend : `src/components/BoussoleESG/README.md` (dépôt `Izzipro_next`).

## Endpoints

Tous sous `/api/boussole/` : liste complète et accès dans `docs/endpoints.md`, section « Boussole ESG » (20 routes).

- `access_control` (`config/packages/security.yaml`), dans cet ordre : `auth/(register|login)` et `referentials`
  **publics** ; `documents` : `ROLE_COMPANY` ; tout le reste : `ROLE_COMPANY`, `ROLE_CONSULTANT`, `ROLE_USER` ou
  `ROLE_ADMIN` (donc **tout utilisateur connecté**).
- **Le vrai contrôle est dans le code** :
  - les contrôleurs refusent tout utilisateur qui n'est pas un `EsgUser` (401), sauf `QuestionController`,
    `ReferentialController`, `ReportController` et les routes `sessions/{uuid}…` (le voter refuse tout autre
    utilisateur) ;
  - voter `Security/Voter/DiagnosticSessionVoter.php` (`VIEW`, `EDIT`, `SUBMIT`, même règle) : session de
    l'entreprise de l'utilisateur, quel que soit son rôle ; `ROLE_ADMIN` : toutes ; `ROLE_CONSULTANT` : refusé
    (aucune relation consultant ↔ entreprises n'existe) ;
  - liste, création et documents : limités à `$user->getCompany()` dans le cas d'usage ; documents : vérification
    du propriétaire (`getCompany()->getId()`) avant téléchargement et suppression.

| Groupe | Routes | Contrôleur | Contrôle dans le code |
|---|---|---|---|
| Compte | `POST auth/register`, `POST auth/login` | `AuthController` | public ; limite de débit (`EventSubscriber/PublicEndpointRateLimitSubscriber`) ; pose les cookies `auth_token_<tenant>` et `XSRF-TOKEN_<tenant>` (1 h) |
| Entreprise | `GET`, `PUT company` | `CompanyController` | `EsgUser`, sa propre entreprise |
| Catalogue | `GET referentials`, `GET questions` | `ReferentialController`, `QuestionController` | aucun ; `referentials` calcule `alreadyHeld` si un `EsgUser` est connecté |
| Diagnostics | `GET`, `POST sessions` ; `GET sessions/historique` ; `GET sessions/{uuid}` ; `PUT …/answers` ; `POST …/submit` ; `GET …/recommendations` | `SessionController` | liste et création : entreprise de l'utilisateur ; `historique` : `denyAccessUnlessGranted('ROLE_COMPANY')` ; le reste : voter |
| Rapport PDF | `GET sessions/{uuid}/report` (statut), `GET …/report/download` | `ReportController` | voter `VIEW` |
| Documents | `GET`, `POST documents` ; `DELETE documents/{id}` ; `GET documents/download/{id}` ; `GET documents/download?path=` | `DocumentController` | `ROLE_COMPANY` + propriétaire |

## Données

Toutes dans la base du tenant. Entités : `src/ESG/Entity/` ; dépôts : `src/ESG/Repository/`.

| Table | Entité | Contenu |
|---|---|---|
| `esg_company` | `EsgCompany` | entreprise : secteur, taille (`size_category`), territoire, `existing_certifications` (JSON, codes saisis au profil) |
| `esg_user` | `EsgUser` | compte ESG (distinct des utilisateurs de la boutique), rôle `ROLE_COMPANY` à l'inscription, identifiant `esg:<email>` |
| `esg_diag_question` | `DiagnosticQuestion` | questions (domaine, type `binary` / `ternary`, poids, `is_active`) |
| `esg_diag_session` | `DiagnosticSession` | un diagnostic : UUID, statut `in_progress` → `completed`, scores par domaine et global, niveau de maturité |
| `esg_diag_answer` | `DiagnosticAnswer` | réponse 0, 1 ou 2 à une question (unique par session et question) |
| `esg_cert_referential` | `CertificationReferential` | catalogue des certifications : seuils par domaine et global, coûts, durées, territoires (code + version uniques) |
| `esg_subsidy_program` | `SubsidyProgram` | programmes de subvention (taux, plafond, territoire) |
| `esg_cert_subsidy` | — | subventions applicables à une certification |
| `esg_cert_recommendation` | `CertificationRecommendation` | recommandation calculée à la soumission : éligibilité, priorité, coûts, récit d'impact |
| `esg_reco_subsidy` | — | subventions retenues pour une recommandation |
| `esg_diag_report` | `DiagnosticReport` | rapport PDF d'une session : statut, chemin, taille, nombre de téléchargements |
| `esg_document` | `EsgDocument` | document justificatif déposé par l'entreprise |
| `esg_odd_mapping` | `OddMapping` | correspondance certifications ↔ objectifs de développement durable (ONU) |

Catalogue au 29/09/2026 : `db_boussoleesg` et `gmasuite` ont 16 questions et 4 référentiels (`b_corp`, `ecovadis`,
`green_key`, `ici_recycle`) ; `db_esgboost` n'a ni question ni référentiel. Administration : EasyAdmin, section
« Boussole ESG » (questions, référentiels, subventions, recommandations, ODD) ; pas d'écran pour les entreprises ni
les comptes.

## Code

| Couche | Fichiers |
|---|---|
| Contrôleurs | `Controller/` : `Auth`, `Company`, `Question`, `Referential`, `Session`, `Report`, `Document` |
| Cas d'usage | `UseCase/Auth/` (`Login`, `Register`), `Company/`, `Diagnostic/` (`CreateSession`, `ListSessions`, `GetSession`, `SaveAnswers`, `SubmitSession`, `GetHistorique`, `GetQuestions`), `Document/`, `Referential/`, `Report/` |
| Services | `Service/ScoringService` (scores, maturité), `RecommendationEngine` (éligibilité, priorité, subventions, récit), `SubsidyCalculator`, `DocumentStorageService` |
| DTO | `DTO/Input/…InputDTO`, `DTO/Output/…OutputDTO` |
| Sécurité | `Security/Voter/DiagnosticSessionVoter.php` |
| Autres | `EventListener/DiagnosticSessionImmutabilityListener` (une session `completed` ne repasse jamais `in_progress`), `Command/RegenerateImpactNarrativesCommand` |
| Administration | `src/Controller/Admin/ESG/` (5 CRUD) |

**Conformes aux conventions** : couches présentes, les 11 dépôts étendent `EntityRepository`, accès par
`TenantEntityManagerProvider`, les 5 CRUD étendent `BaseTenantCrudController`, le handler appelle `switchTenant`.

**Écarts** (à ne pas copier ; ne pas changer le format des erreurs sans le frontend) :
- DTO dans `src/ESG/DTO/`, suffixe `DTO` ; validation dans les contrôleurs, erreurs `{ errors: { champ: message } }`
  en 400 (convention du projet : liste `{ path, message }` en 422).
- `ReportController::download` : logique et `flush()` dans le contrôleur, sans cas d'usage.
- `DocumentController::downloadByPath` interroge le dépôt depuis le contrôleur.
- `GenerateEsgReportHandler` lit la table `tenants` en SQL direct au lieu de `TenantConnectionManager::findTenantByCode()`.

## Diagnostic : de la session au rapport

1. `POST sessions` : refusé (409) si une session `in_progress` existe pour l'entreprise ; la nouvelle session
   **reprend les réponses du dernier diagnostic terminé** (un diagnostic terminé n'est jamais modifié : « modifier »
   crée une nouvelle session).
2. `PUT …/answers` : `{ "answers": [ { "questionId": 2, "answerValue": 1 } ] }`, envoi partiel accepté.
3. `POST …/submit` : 422 s'il manque des réponses aux questions actives (« Questions manquantes : 3, 7 », ce sont
   des `id`) ; sinon scores, niveau de maturité, recommandations et rapport `pending`, puis message
   `GenerateEsgReportMessage`.
- Score d'un domaine : Σ(réponse × poids) / Σ(2 × poids) × 100 ; score global : moyenne des 4 domaines
  (`ScoringService`). Niveaux : `Émergent` (< 40), `Développant` (< 60), `Confirmé` (< 75), `Excellence`.
- Éligibilité : tous les seuils par domaine **et** le seuil global atteints. `gapToThresholdGlobal` = seuil − score,
  ramené à 0 quand le seuil est atteint (0 ne veut pas dire éligible : un domaine peut bloquer).
- Certification déjà détenue (`existing_certifications`, rapprochement sans casse ni séparateurs : `BCORP` = `b_corp`,
  `EsgCompany::holdsCertification`) : `alreadyHeld: true`, non éligible, priorité 6, sans subvention. Appliqué au
  calcul et à la lecture (`RecommendationOutputDTO`), donc aussi aux diagnostics anciens.
- Subventions : programmes liés à la certification **et** dont le territoire est exactement celui de l'entreprise.
- Récits d'impact enregistrés en base : après un changement de leur texte dans `RecommendationEngine`, les régénérer :
  `php bin/console esg:regen-narratives --tenant=db_boussoleesg --all` (sans `--all` : seulement les récits vides).

## Tâches de fond et fichiers

- `GenerateEsgReportMessage` (identifiant du rapport + code du tenant) → transport `esg` (file Redis `messages_esg`) →
  worker `symfony_messenger_worker_esg_v2` → `MessageHandler/GenerateEsgReportHandler.php` : cherche le tenant par
  son code dans la base maître, `switchTenant`, rend `templates/esg/report.html.twig` avec Dompdf (ressources
  distantes désactivées), statut `generating` → `ready` | `failed` (message d'erreur enregistré, pas de relance).
- Stockage privé (jamais servi directement par nginx) :

| Fichiers | Chemin | Téléchargement |
|---|---|---|
| Documents | `var/storage/esg/documents/<id entreprise>/<code>_<horodatage>_<aléa>.<ext>` (PDF, JPEG, PNG, Excel, Word ; 10 Mo ; type vérifié sur le contenu) | `documents/download/{id}` ou `?path=` : `ROLE_COMPANY`, entreprise propriétaire ; ancien dossier de repli `var/uploads/esg/` |
| Rapports PDF | `var/storage/esg/reports/<uuid de session>.pdf` | `…/report/download` : voter `VIEW` ; repli sur l'ancien chemin sous `public/` |

## Tester

- **Aucun test fonctionnel ESG** ; seul `tests/Functional/Security/AnonymousWriteAccessTest.php` mentionne le module
  (exception justifiée pour `/api/boussole/auth/`). Modèle à suivre : `tests/Functional/MemoiresVivantes/`
  (`BookTypeApiTestCase` : JWT, `X-Tenant-Host`, `X-XSRF-TOKEN`).
- `src/ESG/DataFixtures/EsgFixtures.php` (4 subventions, 4 référentiels, questions) : **inutilisable en l'état**,
  `doctrine/doctrine-fixtures-bundle` n'est pas installé. À vérifier : comment le catalogue actuel a été chargé.
- Pour un essai manuel : ne jamais soumettre de diagnostic réel sans nécessité (message envoyé au worker, rapport
  écrit sur disque) ; préférer une transaction annulée à la fin.

## Pièges connus et points à vérifier

Corrigé :
- 23/09/2026 : `GET sessions/{uuid}` renvoyait 404 pour toute session (EntityManager supprimé par erreur,
  erreur masquée en 404) ; modifier un diagnostic renvoyait 422 (nouvelle session vide).
- 26/09/2026 : un compte ESG pouvait prendre la place d'un administrateur de même e-mail (`c326d0b`).
- Un `ROLE_CONSULTANT` avait accès à toutes les sessions du tenant (le voter refuse désormais).

Corrections proposées, par ordre de gravité :
1. **Tenant de repli codé en dur** : `SubmitSessionUseCase` envoie `'boussoleesg'` si le code du tenant est inconnu ;
   le worker ouvrirait alors le rapport de même identifiant dans une autre base. Refuser plutôt que deviner.
2. **Compte désactivé encore actif jusqu'à 1 h** : `is_active` (compte et entreprise) n'est vérifié qu'à la
   connexion ; un jeton déjà émis reste valable. À vérifier : `UserChecker` ne semble pas s'appliquer au pare-feu `api`.
3. **Erreurs masquées** : `saveAnswers` renvoie 403, `ReportController::getStatus` et `GET company` 404, `PUT company` 400,
   `register` 409 pour **toute** exception, avec le message brut (y compris une erreur SQL). C'est ce qui a caché le
   bug du 23/09. Ne capturer que les exceptions HTTP, comme `submit`.
4. **Fichiers de tous les tenants dans les mêmes dossiers** : `documents/<id entreprise>/` mélange des entreprises de
   bases différentes qui ont le même identifiant. Pas de fuite (l'accès passe par la base du tenant), mais risque au
   nettoyage ou à la suppression. Ajouter le code du tenant au chemin.
5. **Extension du fichier déposé** prise du nom fourni par le client (le type, lui, est vérifié) : à vérifier, en-têtes
   `Content-Type` et `Content-Disposition` au téléchargement.
6. `historique` exige `ROLE_COMPANY`, les autres routes non ; et `historique` ne renvoie pas les réponses (le
   frontend affichait « Non » partout).
7. Ordre des recommandations dans `GET sessions/{uuid}` non garanti (pas d'`OrderBy` ; le commentaire du DTO dit
   le contraire) ; `/recommendations` idem.
8. `STORAGE_ESG_ADAPTER`, `STORAGE_ESG_DIR` : définies dans `.env`, lues par aucun code (chemins codés en dur).

Points métier à trancher (hors code) :
- **Catalogue différent du frontend** : codes du frontend (`ECOCERT_26000`, `CLIMATE_ACTIVATOR`, `BIO_CANADA`,
  `ALIMENTS_QUEBEC`, `BIO_COR`, `GLOBALGAP`) absents du backend ; les profils enregistrent pourtant ces codes. En
  attente du chef de projet.
- Questions `binary` avec la valeur 1 en base (questions 3 et 11 d'une session) : attendu 0 ou 2 ? Aucune validation.
- `green_key` (territoires MQ, CB, INT) recommandée à des entreprises du Québec : le moteur ne filtre pas les
  certifications par territoire.
- `sub_transition_canada` (territoire `CA`) jamais proposée au Québec (`QC`) : comparaison exacte du territoire.
- Priorité calculée sur l'écart global seul : une certification bloquée par un domaine reçoit la priorité 3.
- Nommage : `scoreClimate` d'un côté, `climate_activator` (enum, clés de `scores`) de l'autre ; ne rien renommer
  sans le frontend.
- `is_verified` est `false` à l'inscription et n'est jamais exigé : voulu ?
- Tenant `esgboost` sans questions ni référentiels : aucun diagnostic possible.

## Tenir cette fiche à jour

À chaque nouvel endpoint, table, règle de calcul, type de message ou changement de contrat avec le frontend : mettre
à jour cette fiche, puis régénérer `docs/endpoints.md` (commande en tête du fichier).
