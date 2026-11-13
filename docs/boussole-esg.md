# Module Boussole ESG

Autodiagnostic ESG d'une entreprise : questionnaire en 4 domaines, scores, recommandations de certifications (avec
subventions), rapport PDF et dépôt de documents justificatifs. Contexte : `docs/architecture.md`.
Code : `src/ESG/` (module autonome). Tenant principal : `boussoleesg` (base `db_boussoleesg`).
Côté frontend : `src/components/BoussoleESG/README.md` (dépôt `Izzipro_next`).

## Endpoints

Tous sous `/api/boussole/` : liste complète et accès dans `docs/endpoints.md`, section « Boussole ESG » (26 routes).

- `access_control` (`config/packages/security.yaml`), dans cet ordre : `auth/(register|login)` et `referentials`
  **publics** ; `team` : `ROLE_BOUSSOLE_CONSEILLER`, `ROLE_ADMIN` ou `ROLE_SUPER_ADMIN` (voir « Équipe ») ; `documents` :
  `ROLE_COMPANY` ; tout le reste : `ROLE_COMPANY`, `ROLE_USER` ou `ROLE_ADMIN` (donc **tout utilisateur connecté**).
  `ROLE_CONSULTANT` a été retiré le 13/11/2026 : aucun compte d'aucun site ne le portait.
- **Le vrai contrôle est dans le code** :
  - les contrôleurs refusent tout utilisateur qui n'est pas un `EsgUser` (401), sauf `QuestionController`,
    `ReferentialController`, `ReportController`, `MeController`, `TeamController` et les routes `sessions/{uuid}…`
    (le voter refuse tout autre utilisateur que l'entreprise et l'équipe) ;
  - voter `Security/Voter/DiagnosticSessionVoter.php` (`VIEW`, `EDIT`, `SUBMIT`) : session de l'entreprise de
    l'utilisateur, quel que soit son rôle ; équipe de la Boussole (voir « Équipe ») : `VIEW` sur toutes les sessions du
    site, jamais `EDIT` ni `SUBMIT` ;
  - liste, création et documents : limités à `$user->getCompany()` dans le cas d'usage ; documents : vérification
    du propriétaire (`getCompany()->getId()`) avant téléchargement et suppression.

| Groupe | Routes | Contrôleur | Contrôle dans le code |
|---|---|---|---|
| Compte | `POST auth/register`, `POST auth/login` | `AuthController` | public ; limite de débit (`EventSubscriber/PublicEndpointRateLimitSubscriber`) ; pose les cookies `auth_token_<tenant>` et `XSRF-TOKEN_<tenant>` (1 h) ; inscription avec une adresse déjà connue : **409 `EMAIL_UNAVAILABLE`** (voir « Équipe ») |
| Qui suis-je | `GET me` | `MeController` | tout compte connecté ; `kind` : `company`, `team` ou `none` |
| Équipe | `GET team/members`, `PUT team/members/{userId}`, `GET team/companies`, `GET team/companies/{id}`, `GET team/documents/{id}/download` | `TeamController` | voter `BOUSSOLE_TEAM` ; l'attribution : `ROLE_ADMIN` |
| Entreprise | `GET`, `PUT company` | `CompanyController` | `EsgUser`, sa propre entreprise |
| Catalogue | `GET referentials`, `GET questions` | `ReferentialController`, `QuestionController` | aucun ; `referentials` calcule `alreadyHeld` si un `EsgUser` est connecté |
| Diagnostics | `GET`, `POST sessions` ; `GET sessions/historique` ; `GET sessions/{uuid}` ; `PUT …/answers` ; `POST …/submit` ; `GET …/recommendations` | `SessionController` | liste et création : entreprise de l'utilisateur ; `historique` : `denyAccessUnlessGranted('ROLE_COMPANY')` ; le reste : voter |
| Rapport PDF | `GET sessions/{uuid}/report` (statut), `GET …/report/download` | `ReportController` | voter `VIEW` |
| Documents | `GET`, `POST documents` ; `DELETE documents/{id}` ; `GET documents/download/{id}` ; `GET documents/download?path=` | `DocumentController` | `ROLE_COMPANY` + propriétaire |

## Équipe : le conseiller de la Boussole (lot BOUSSOLE-CONSEILLER, 13/11/2026)

**Qui.** L'équipe de la Boussole d'un site, ce sont des comptes de la **base commune** (table `user`), jamais des comptes
d'entreprise (`esg_user`) : le conseiller (`ROLE_BOUSSOLE_CONSEILLER`), l'administrateur du site (`ROLE_ADMIN`) et le super
administrateur (`TeamRoles::BOUSSOLE_ACCESS`). Le rôle suit la convention commune des rôles d'équipe
(`docs/memoires-vivantes.md`, « Convention commune des rôles d'équipe ») : déclaré dans `App\Security\TeamRoles::ALL`
(« Conseiller (Boussole ESG) »), posé et retiré par `TeamRoleService`, journalisé dans `content_audit_log` (ressource
`user_team`, action `grant` ou `revoke`), `ROLE_USER_INTERNET` ajouté d'office, champ `teamRoles` dans les réponses.
Aucune attribution automatique. L'équipe se connecte par la **connexion commune du site** (`POST /api/login`), pas par
`/api/boussole/auth/login`, qui reste celle des entreprises.

**Attribuer le rôle**, trois chemins, tous journalisés :
- l'écran Utilisateurs d'EasyAdmin (le rôle y est attribuable par la convention) ;
- `PUT /api/boussole/team/members/{userId}`, corps `{"member": true | false}`, réservé à `ROLE_ADMIN`, idempotent →
  `{id, isAdvisor, teamRoles, roles, changed}` ; 400 `INVALID_BODY`, 403 `OWNER_PROTECTED` (la cible est un super
  administrateur et l'appelant ne l'est pas), 404 `USER_NOT_FOUND` ;
- la commande `php bin/console app:boussole:team:grant <code du site> <courriel> [--revoke]` (repère `console` au journal).

**Ce que l'équipe lit** (voter `Security/Voter/BoussoleTeamVoter.php`, attribut `BOUSSOLE_TEAM`), toutes les entreprises
du site, sans assignation :
- `GET /api/boussole/team/members` → `[{id, fullName, email, teamRoles}]`, les conseillers, par nom ;
- `GET /api/boussole/team/companies?q=&page=&limit=` (recherche sans casse sur le nom, la ville et le courriel de contact ;
  50 par page, 200 au plus) → `{total, page, limit, companies: [fiche de `GET company` + isActive, createdAt, usersCount,
  sessionsCount, documentsCount, lastSession: {uuid, status, scoreGlobal, maturityLevel, completedAt} | null]}`, par nom ;
- `GET /api/boussole/team/companies/{id}` → `{company, users: [{id, email, firstName, lastName, isActive}], sessions:
  [forme de `GET sessions`], documents: [forme de `GET documents`, `downloadUrl` = la route d'équipe]}` ; 404
  `COMPANY_NOT_FOUND` ;
- le diagnostic et le rapport par les **routes existantes** `GET sessions/{uuid}`, `…/recommendations`, `…/report` et
  `…/report/download`, avec leurs formes d'aujourd'hui (la règle des sessions donne `VIEW` à l'équipe) ;
- `GET /api/boussole/team/documents/{id}/download` : le fichier d'un document ; 404 `DOCUMENT_NOT_FOUND`.

**Ce que l'équipe n'écrit pas.** Rien dans ce lot : créer un diagnostic, modifier une fiche (401, réservés aux comptes
d'entreprise), répondre, soumettre, déposer ou supprimer un document (403).

**Journal des consultations (Loi 25).** L'équipe lit des données d'entreprises clientes : `Service/TeamViewJournal`
inscrit dans `content_audit_log` (ressource `boussole_company`, identifiant = l'entreprise, action `view`, compte et
date par le journal, `after` = `{what, company, …}`) chaque lecture par un compte d'équipe du détail d'une entreprise
(`what: company`), d'un diagnostic ou de ses recommandations (`session`, avec `sessionUuid`), le téléchargement d'un
rapport (`report`) et celui d'un document (`document`, avec `documentId` et `name`). Les listes et l'état d'un rapport
(prêt ou non) ne sont pas journalisés ; une entreprise qui lit ses propres données n'écrit rien. Lecture du journal :
`GET /api/landingpage-audit?resource=boussole_company&resourceId=<id de l'entreprise>` (administrateur du site ; une consultation ne se « restaure » pas).

**Qui suis-je.** `GET /api/boussole/me`, pour tout compte connecté → `{kind, id, email, firstName, lastName, roles,
teamRoles, isAdvisor, company}`. `kind` : `company` (compte d'entreprise, `company` = sa fiche), `team` (conseiller,
administrateur du site, super administrateur ; `company` = null) ou `none` (un autre compte du site, un client de la
boutique par exemple : aucun droit dans la Boussole). 401 sans connexion.

**Inscription avec une adresse déjà connue.** `POST auth/register` répond toujours 409, avec `{error, code:
"EMAIL_UNAVAILABLE"}` et un seul message, que l'adresse soit celle d'un compte de la Boussole ou d'un compte du site : on
ne dit pas à un inconnu de quel côté le compte existe. La Boussole garde ses comptes d'entreprise séparés (`esg_user`).

Test : `tests/Functional/Boussole/BoussoleTeamTest.php`.

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
| Sécurité | `Security/Voter/DiagnosticSessionVoter.php`, `Security/Voter/BoussoleTeamVoter.php` |
| Équipe | `Controller/TeamController`, `Controller/MeController`, `UseCase/Team/`, `Service/TeamViewJournal`, `Command/BoussoleTeamGrantCommand` (dans `src/Command/`) |
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

## Module désactivé (console de pilotage, lot 1 bis, 04/11/2026)

En mode `live` de la plateforme, toutes les routes `/api/boussole/*` d'un site dont le module Boussole ESG est désactivé
(`entreprise.is_boussole_esg_active`) répondent **403 `MODULE_INACTIVE`** (`module: boussole`, `message`), connecté ou non.
Mode `demo` (défaut) : rien n'est gardé. C'est le premier réglage de module de la Boussole ; l'état du module est lu dans
`GET /api/site/features` (`module.boussole`). Détail : `docs/architecture.md`, « Modules et mode de la plateforme ».

## Tester

- Premier test fonctionnel : `tests/Functional/Boussole/BoussoleTeamTest.php` (équipe, journal des consultations,
  cloisonnement des entreprises, inscription, « qui suis-je ») ; il crée lui-même ses entreprises, ses fichiers et leurs
  dossiers (le modèle des tests n'a aucune donnée de la Boussole). Le questionnaire, le calcul des scores et le rapport
  n'ont pas encore de test. `tests/Functional/Security/AnonymousWriteAccessTest.php` garde son exception pour
  `/api/boussole/auth/`.
- `src/ESG/DataFixtures/EsgFixtures.php` (4 subventions, 4 référentiels, questions) : **inutilisable en l'état**,
  `doctrine/doctrine-fixtures-bundle` n'est pas installé. À vérifier : comment le catalogue actuel a été chargé.
- Pour un essai manuel : ne jamais soumettre de diagnostic réel sans nécessité (message envoyé au worker, rapport
  écrit sur disque) ; préférer une transaction annulée à la fin.

## Pièges connus et points à vérifier

Corrigé :
- 23/09/2026 : `GET sessions/{uuid}` renvoyait 404 pour toute session (EntityManager supprimé par erreur,
  erreur masquée en 404) ; modifier un diagnostic renvoyait 422 (nouvelle session vide).
- 26/09/2026 : un compte ESG pouvait prendre la place d'un administrateur de même e-mail (`c326d0b`).
- Un `ROLE_CONSULTANT` avait accès à toutes les sessions du tenant (le voter refuse désormais) ; rôle retiré le
  13/11/2026, remplacé par `ROLE_BOUSSOLE_CONSEILLER` (« Équipe »).

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
