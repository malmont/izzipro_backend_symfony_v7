# Incidents et signalements : relevé des erreurs de toutes les applications et bogues signalés par les clients

Cadrage du 10/11/2026, décidé par le propriétaire ; ajouts du 18/11/2026 (§ « Ajouts du propriétaire »). Nom du lot : **INCIDENTS** (INC-1 à
INC-5). **INC-1a codé le 18/11/2026** (§ « Lot INC-1a », sous Opus, à faire vérifier par Fable) ; INC-1b (entrée d'une source tierce)
reporté par le propriétaire (pas d'outil tiers pour l'instant) ; INC-2 à INC-5 à venir.
Deux circuits, deux publics, deux outils déjà en place : pas de nouvelle plateforme.

## Principe

| Circuit | Qui signale | Qui traite | Où | Objet |
|---|---|---|---|---|
| **Incidents de la plateforme** | les applications elles-mêmes (erreurs serveur et erreurs d'écran) et leurs utilisateurs (bouton « Signaler un problème ») | les administrateurs de la plateforme (rôle « support plateforme » ou super administrateur) | **EasyAdmin, niveau plateforme** (console de pilotage, à côté de « Demandes des sites ») | bogues, plantages, appels qui échouent, dans le site, la boutique, la caisse, l'application mobile, Living Memory, l'application de gestion |
| **Demandes des acheteurs d'une boutique** | les clients finaux d'un commerçant | le commerçant ou son équipe | **application web de gestion** (module Assistance, `docs/assistance.md`) | questions, plaintes, retours, authentification du client |

Règle : un écran qui concerne plusieurs sites ou la plateforme vit dans EasyAdmin ; un écran qui fait tourner une entreprise sur son
site vit dans l'application de gestion. Le backend sert les deux. Les deux circuits ont **deux tables, deux routes, deux pages** ; un
signalement d'acheteur ne remonte jamais à la plateforme, un bogue de la plateforme ne tombe jamais dans le support d'un commerçant.

## Ce qui existe déjà et sera réutilisé

- `pos_health_alert` et `Services/PosStripeService/PosHealthService` (M1) : alertes de la caisse avec empreinte, niveau, première et
  dernière occurrence, `resolved_at`, pas de renvoi avant 6 h, courriel. Le modèle des incidents en est la généralisation.
- `app:site:weekly-digest` et son minuteur (lundi 07:00 UTC) : le courriel quotidien des incidents suit le même gabarit et la même commande.
- Console de pilotage (`/admin/plateforme`, `docs/console-pilotage.md`) : la page « Incidents » s'y ajoute comme onglet.
- Rôles : `ROLE_SUPER_ADMIN` ; un rôle **`ROLE_PLATFORM_SUPPORT`** à créer, limité aux incidents et aux signalements (pas aux droits, offres
  ni réglages de la plateforme).
- Module Assistance (`docs/assistance.md`) : les signalements des acheteurs y vont, pas ici.
- Journal d'audit et limites par site et par IP (`docs/architecture.md`) : réutilisés tels quels.

## Modèle (base maître, additif)

- `platform_incident` : `id`, `fingerprint` (empreinte : application + écran ou route + message normalisé), `tenant_code` (nullable :
  plateforme), `app` (`web`, `boutique`, `pos`, `mobile`, `livingmemory`, `gestion`, `backend`), `app_version`, `screen` ou `route`,
  `level` (`error`, `crash`, `warning`), `message` (200 caractères), `context` (JSON borné, **sans donnée personnelle**), `count`,
  `first_seen_at`, `last_seen_at`, `status` (`new`, `acknowledged`, `fixed`, `ignored`), `assigned_to`, `note`, `external_url` (lien vers
  l'outil de tickets de l'équipe, facultatif), `fixed_in` (commit ou version).
- `platform_incident_event` : occurrences détaillées (bornées à N par incident, les plus anciennes purgées), `occurred_at`, `user_hash`
  (empreinte, jamais le courriel), `request_id`.
- `platform_bug_report` : signalement d'un utilisateur : `tenant_code`, `app`, `app_version`, `screen`, `description`, `screenshot_path`
  (stockage privé, 5 Mo), `reporter_kind` (`site_admin`, `cashier`, `partner`, `customer`), `reporter_hash`, `status` (`received`,
  `in_progress`, `fixed`, `wont_fix`), `incident_id` (nullable, rattaché à un incident), `answer` (texte montré au signaleur), dates.
- Purge : événements au-delà de 90 jours, incidents `fixed` ou `ignored` au-delà d'un an ; signalements gardés.

## Routes (additives)

- `POST /api/incidents/report` : appelée par le gestionnaire d'erreurs global de chaque application. Corps `{ app, appVersion, screen?,
  route?, level, message, context? }`. Authentification : jeton de l'application ou de l'utilisateur quand il y en a un, sinon jeton public
  par site avec limite stricte (par site et par IP, comme `docs/architecture.md`). Réponse 202 `{ incidentId, fingerprint }`. Le backend
  regroupe par empreinte, incrémente, met à jour `last_seen_at`.
- Erreurs serveur : un gestionnaire Monolog `platform_incident` enregistre toute exception 5xx (et les 4xx choisis : 429, 409 de
  paiement) dans la même table, avec `app: backend`, `route`, `tenant_code`. Jamais le corps des requêtes ni les secrets.
- `POST /api/incidents/bug-reports` (multipart : description, capture facultative) et `GET /api/incidents/bug-reports` (ceux du signaleur,
  avec leur état et la réponse) : pour les applications.
- Super administrateur et support plateforme : `GET /api/platform/incidents?status=&app=&tenant=`, `PATCH /api/platform/incidents/{id}`
  (statut, note, affectation, lien externe, `fixed_in`), `GET /api/platform/bug-reports`, `PATCH /api/platform/bug-reports/{id}` (statut,
  réponse, rattachement à un incident). Partenaire : les mêmes en lecture, **filtrées sur ses sites** (espace partenaire).

## Écrans

- EasyAdmin, console de pilotage : onglet **« Incidents »** (liste groupée, filtres site / application / statut / niveau, détail avec les
  dernières occurrences et le contexte, actions : prendre en charge, corrigé avec commit, ignorer, lien externe) et onglet **« Signalements »**
  (liste, capture, réponse au signaleur, rattacher à un incident). Compteur dans le menu.
- Courriel **quotidien** (07:00 UTC, commande `app:platform:incidents-digest` + minuteur systemd) : nouveaux incidents de la veille, incidents qui
  reviennent après correction, signalements non répondus depuis 48 h. Aucun courriel quand il n'y a rien.
- Espace partenaire (quand il existera) : nombre d'incidents et de signalements par site, en lecture.
- Dans chaque application, pour l'utilisateur : bouton **« Signaler un problème »** (description, capture, contexte technique joint
  automatiquement) et écran **« Mes signalements »** avec l'état et la réponse.

## Lots

1. **Backend, relevé et console** (1 jour) : tables, gestionnaire Monolog, `POST /api/incidents/report`, routes plateforme, onglet
   « Incidents », rôle `ROLE_PLATFORM_SUPPORT`, courriel quotidien et minuteur, purge, tests, doc.
2. **Fronts, gestionnaire d'erreurs** (un prompt par session) : Next (site et boutique), caisse Flutter, application mobile e-commerce,
   Living Memory, application de gestion. Vingt lignes chacun : brancher le gestionnaire global sur `POST /api/incidents/report`, sans
   donnée personnelle, avec version et écran.
3. **Backend, signalements** (1 jour) : `bug-reports`, onglet « Signalements », réponse au signaleur, rattachement, tests, doc.
4. **Fronts, bouton et « Mes signalements »** (un prompt par session).
5. **Espace partenaire** : vue filtrée (dans le chantier de l'espace partenaire).

Responsabilités : backend et EasyAdmin par la session backend (« posflutter backend ») ; l'application web de gestion par sa session ;
les autres fronts par leurs sessions, sur prompt. L'ordre 1 → 2 → 3 → 4 permet de voir les erreurs réelles avant d'ouvrir le signalement
aux utilisateurs.

## Règles

- Aucune donnée personnelle dans un incident : pas de courriel, pas de nom, pas de corps de requête ; empreintes seulement. Les captures
  d'écran des signalements sont dans le stockage privé, visibles des administrateurs de la plateforme et du signaleur.
- Routes additives, rien d'existant modifié ; `pos_health_alert` continue de fonctionner tel quel et sera rattaché aux incidents dans un
  second temps.
- Un seul outil pour les traiter : EasyAdmin. Un outil de tickets externe (GitHub ou autre) ne reçoit que des liens, jamais l'original.
- Non-régression : les suites existantes passent sans modification ; les nouvelles routes ont leurs tests ; la limite par site protège
  contre une application en boucle d'erreurs.

## Ajouts du propriétaire du 18/11/2026 (relayés par le chef), à intégrer au contrat du lot INCIDENTS

1. **Sources** : toutes les applications, pas seulement la caisse (« Signaler un problème » de la caisse aujourd'hui) : application de
   gestion MAUI, applications Flutter (caisse, boutique mobile, Living Memory), fronts web (Next) et backend (gestionnaire Monolog). Le lot 2
   (un prompt par session) les branche une à une sur `POST /api/incidents/report`.
2. **EasyAdmin, onglet réservé au super administrateur** : une BOÎTE UNIQUE des incidents techniques, qui reçoit aussi ceux d'une
   application tierce de suivi des erreurs par une ENTRÉE AUTHENTIFIÉE (`POST /api/incidents/ingest/{source}` : clé propre à la source,
   rangée chiffrée, signature du corps vérifiée, limite par source ; la source n'écrit qu'un incident, jamais un compte ni un réglage).
   Chaque incident porte le site, l'application, la version, la gravité, une SIGNATURE qui regroupe les doublons (l'empreinte du modèle,
   calculée par le serveur, jamais fournie telle quelle par la source) et un état (`new`, `acknowledged`, `fixed`, `ignored`).
3. **Escalade depuis l'Assistance** : un ticket du module Assistance (`docs/assistance.md`) qui est en fait un bogue s'attribue d'un geste
   à la plateforme (« Transmettre à l'équipe technique », agent du site) : un incident `app: support`, lié au ticket d'origine
   (`support_ticket_id` + site), sans copie du fil ni des pièces jointes du client (un résumé écrit par l'agent, masqué comme DON-1). Le
   commerçant lit seulement l'ÉTAT sur son ticket (« transmis à l'équipe technique », « corrigé », « ne sera pas corrigé ») et la réponse
   publique de la plateforme ; jamais l'onglet, ni la note interne, ni les autres sites. Ceci précise la règle « un signalement d'acheteur
   ne remonte jamais à la plateforme » : il ne remonte que par ce geste explicite du commerçant.
4. **Données** : aucune donnée personnelle d'un client final dans un incident (masquage commun avec DON-1 : courriel « m***@domaine »,
   téléphone « •••• 1234 », nom retiré, corps des requêtes jamais repris) ; un site ne lit que SES incidents et signalements (routes du
   site filtrées par le site du jeton, essai de cloisonnement) ; l'agence (quand elle lira) seulement ceux de ses sites.

Effet sur les lots : lot 1 + entrée authentifiée de la source tierce et onglet super administrateur ; lot 3 + escalade depuis
l'Assistance (une route du site et un état lu sur le ticket) ; un essai de cloisonnement par site et un garde-fou du masquage dans chacun.

## Décisions à prendre au lancement

1. Les acheteurs d'une boutique voient-ils un bouton « Signaler un problème » vers la plateforme, ou seulement vers le commerçant ? (Proposé :
   seulement vers le commerçant, module Assistance.)
2. Le courriel quotidien part-il au propriétaire seul ou à tout le support plateforme ?
3. Outil de tickets externe de l'équipe, pour le lien `external_url`.
4. Durée de conservation des captures d'écran (proposé : 180 jours).

## Lot INC-1a : relevé des erreurs et boîte unique du super administrateur (codé le 18/11/2026, contrat validé par le chef)

Base maître, `ops/sql/platform_incident_master.sql` (SQL seul, idempotent, relu par `tests/bootstrap.php`) : `platform_incident` (empreinte
unique, site, application, version, écran ou route, gravité, message de 200 caractères, contexte, compte, sans jeton ou non, dates, état
`new` / `acknowledged` / `fixed` / `ignored`, marque `reopened`, note, lien externe, `fixed_in`) et `platform_incident_event` (50 dernières
occurrences par incident : date, version, empreinte du compte, identifiant de requête, répétitions). Code : `src/Incident/`.

- **`POST /api/incidents/report`** `{ app, appVersion?, screen?, route?, level?, message, context? }` → 202 `{ incidentId, fingerprint }`.
  - `app` : `web`, `boutique`, `pos`, `mobile`, `livingmemory`, `gestion` (`backend` est réservé au serveur) ; `level` : `error` (défaut),
    `crash`, `warning` ; `appVersion` : 40 caractères `[\w.+-]`.
  - Le **site** est celui de la requête (hôte ou jeton), jamais celui du corps ; l'**empreinte** est calculée par le serveur (site,
    application, écran ou route, message normalisé : chiffres, identifiants et jetons sans effet), jamais reprise du corps.
  - **Masquage** (`App\Services\Privacy\PersonalDataMask::text`, commun avec DON-1) : courriel « m***@domaine », numéro de 7 chiffres ou
    plus « •••• 1234 », JWT et longues suites remplacés par « [jeton] », valeur de `password`, `token`, `secret`, `authorization`, `pin`…
    remplacée par « [masqué] » ; requête et ancre retirées d'une adresse.
  - **Contexte** : liste blanche `url`, `method`, `httpStatus`, `errorCode`, `component`, `action`, `os`, `osVersion`, `device`, `locale`,
    `online`, `durationMs`, `stack` (1 500 caractères), valeurs simples seulement, 2 Ko au plus (la pile part la première). Tout autre
    champ (un nom, un courriel, un corps de requête) est retiré.
  - **Avec le jeton** de l'utilisateur : 120 rapports par site et par compte en 5 minutes ; le compte n'est gardé que par une empreinte
    (16 caractères). Un jeton expiré : 401 du pare-feu, renvoyer sans jeton.
  - **Sans jeton** : 30 rapports par site et par adresse IP en 5 minutes (429 `RATE_LIMITED`, `Retry-After`), et au plus **50 incidents
    nouveaux par site et par jour** (UTC) ; au-delà, un seul incident « Plafond du jour atteint » du site (`app: backend`, `warning`)
    compte les rapports, rien d'autre n'est créé. Un incident déjà connu compte toujours.
  - 422 `VALIDATION_FAILED { fields }` (`REQUIRED`, `INVALID_VALUE`). Route ouverte pendant la construction d'une boutique
    (`StorefrontGateSubscriber`).
- **Pannes du serveur** (`ServerErrorIncidentSubscriber`) : toute réponse **5xx** devient un incident `app: backend` (route nommée, site,
  classe et texte masqué de la panne, sans paramètres SQL ni texte entre guillemets ; jamais le corps ni les en-têtes). Une 4xx, 429
  compris, n'est pas une panne. **Au plus une écriture par empreinte et par minute** : les autres sont comptées en cache et écrites avec
  la suivante (rien n'est perdu). L'écriture se fait en fin de requête, après la réponse ; une panne de l'incident ne change jamais la
  réponse.
- **`framework.handle_all_throwables: true`** (décision du chef, 18/11/2026) : une `\Error` (TypeError…) levée pendant une requête passe par
  `kernel.exception` comme une exception : réponse JSON neutre (`{"error":"Internal Server Error"}`, `ApiExceptionListener`) au lieu d'une
  500 brute, et incident. Lu par le seul noyau HTTP : la console et les workers ne changent pas.
- **Super administrateur seul** (`ROLE_PLATFORM_SUPPORT` reporté) :
  - `GET /api/platform/incidents?status=&app=&tenant=&level=&page=&limit=` → `{ incidents [...], total, page, limit }` (50 par défaut,
    100 au plus) ; 422 pour un filtre inconnu ;
  - `GET /api/platform/incidents/{id}` → l'incident et ses 20 dernières occurrences (`events`) ; 404 `INCIDENT_NOT_FOUND` ;
  - `PATCH /api/platform/incidents/{id}` `{ status?, note?, externalUrl? (http ou https), fixedIn? (80 caractères) }`. Un incident corrigé
    qui revient redevient `new`, marqué `reopened` ; un incident ignoré reste ignoré et compte.
  - EasyAdmin : **`/admin/plateforme/incidents`**, ouvert depuis la page « Plateforme » (bouton « Incidents de la plateforme ») : liste
    filtrée, contexte, actions (état, corrigé dans, lien externe, note). Aucun changement du menu (compteur après le lot G-1).
- **Courriel du jour** : `app:platform:incidents-digest` (minuteur `ops/systemd/platform-incidents-digest.timer`, 07:00 UTC, **à installer
  par le propriétaire**) : incidents nouveaux des dernières 24 heures et incidents revenus, à l'adresse de la plateforme (variable
  `POS_ALERT_PLATFORM_EMAIL`, la même que les alertes de la caisse, posée hors dépôt ; sans elle, rien ne part) ; aucun courriel sans
  incident ; `--dry-run` compte sans envoyer. Puis **purge** : occurrences de plus de 90 jours, incidents corrigés ou ignorés depuis plus
  d'un an.
- Essais : `tests/Functional/Incident/PlatformIncidentTest.php` (masquage et garde-fou, regroupement, site de la requête, limites,
  plafond du jour, \Error et rafale, cloisonnement, écran, courriel et purge). Une \Error naît dans un contrôleur par
  `tests/Fake/FakeControllerErrorListener` (environnement de test seulement, en-tête `X-Test-Raise`).

## Point de reprise (18/11/2026, arrêt du soir demandé par le propriétaire)

- **Fait** : INC-1a codé, essais et mutations joués, remis au chaperon sur la branche `lot-inc1a` (au-dessus de DON-1, `lot-don1`). Il
  part à la bascule de demain. Il y aura deux commits : `framework.handle_all_throwables: true` à part (changement global, limité aux
  `\Error`), puis le lot.
- **À la bascule** : SQL maître `ops/sql/platform_incident_master.sql` avant l'échange du code, puis `cache:clear` (routes, services,
  écouteur nouveau). Le propriétaire installe le minuteur `ops/systemd/platform-incidents-digest.{service,timer}`. La variable
  `POS_ALERT_PLATFORM_EMAIL` est déjà posée pour la caisse.
- **À suivre : INC-2**, le branchement des sources une à une : caisse, application de gestion MAUI, Flutter (boutique mobile, Living
  Memory), fronts web Next. On commence par le contrat de ce que chaque front envoie à `POST /api/incidents/report`, relayé par le chef.
- **Reporté : INC-1b** (entrée authentifiée d'un outil tiers), décision du propriétaire du 18/11/2026 : pas d'outil tiers pour l'instant.
- **Ensuite** : INC-3 (signalements, escalade depuis l'Assistance), INC-4 (bouton « Signaler un problème »), INC-5 (espace partenaire).
  `ROLE_PLATFORM_SUPPORT` et le compteur du menu viendront après le lot G-1.
