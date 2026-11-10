# Incidents et signalements : relevé des erreurs de toutes les applications et bogues signalés par les clients

Cadrage du 10/11/2026, décidé par le propriétaire. Chantier à lancer **après la fin de l'application de gestion**. Rien n'est codé.
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

## Décisions à prendre au lancement

1. Les acheteurs d'une boutique voient-ils un bouton « Signaler un problème » vers la plateforme, ou seulement vers le commerçant ? (Proposé :
   seulement vers le commerçant, module Assistance.)
2. Le courriel quotidien part-il au propriétaire seul ou à tout le support plateforme ?
3. Outil de tickets externe de l'équipe, pour le lien `external_url`.
4. Durée de conservation des captures d'écran (proposé : 180 jours).
