# Module Assistance (cadrage du 07/11/2026, à construire plus tard)

Décision du propriétaire : un module d'assistance pour la plateforme (bugs, plaintes) et pour les commerçants (service après-vente de la
boutique), avec l'IA en aide au répondeur humain, et plus tard un chat client borné. **Rien n'est codé** ; cette fiche fixe le découpage et
qui fait quoi, pour que chaque session (backend, Next, MAUI, posflutter) sache ce qu'elle aura à produire le moment venu.

## Ordre des lots

| Lot | Contenu | Pourquoi dans cet ordre |
|---|---|---|
| 1 | **Boîte de demandes unique** : ticket rattaché à une commande, un client, une vente de caisse ou un site ; état (nouveau, pris en charge, en attente du client, fermé), canal, priorité, agent, messages (courriel entrant et sortant, notes internes), pièces jointes | Sans circuit des demandes, l'IA n'a rien à lire ni à apprendre |
| 2 | **Entrées** : formulaire du site (« un problème avec ma commande », prérempli), courriel entrant (une adresse par site, un courriel = un ticket ou une réponse), bouton « Signaler un problème » de la caisse avec contexte technique automatique (poste, version, dernière opération, état du terminal, journal récent) | Les demandes arrivent déjà par ces trois voies |
| 3 | **IA assistante du répondeur** : résumé du ticket, lecture de la commande (fiche et actions permises existantes), proposition de réponse dans la langue du client, action proposée et confirmée (rembourser, renvoyer un reçu, enregistrer un retour, changer l'adresse : services des lots 3 et 7 du 07/11/2026) | Réponse en un clic, sans erreur ; l'humain garde la main |
| 4 | **Chat client autonome borné** sur le site et l'application (statut de commande, politique de retour, horaires, délais), avec passage à l'humain au moindre doute et création du ticket | Le risque de réponse fausse est le plus grand : il faut d'abord les règles et l'historique |

## Responsabilités

**Backend (app_v2)** : entités `support_ticket`, `support_message`, `support_attachment` (par site) ; `POST/GET/PATCH /api/support/tickets`,
`POST /api/support/tickets/{id}/messages`, `POST /api/support/report` (caisse, avec contexte) ; réception des courriels (une adresse par site,
rattachement par référence de commande ou par courriel du client) ; envoi des réponses par `EmailSenderService` dans la langue du client ;
outils de l'assistant (`summarize_ticket`, `draft_reply`, et les actions confirmées déjà existantes) et un palier de droits (le module
d'assistance est une fonctionnalité gouvernée, comme les autres) ; notifications (courriel au marchand à l'arrivée d'un ticket, relances) ;
tableau « Assistance » dans le back-office EasyAdmin ; étapes du cheminement (adresse de réception configurée, premier ticket traité).

**Site Next (boutique)** : formulaire « Un problème avec ma commande » depuis le détail d'une commande (motif, message, pièces jointes), suivi
du ticket dans le compte client, et plus tard le chat borné (lot 4).

**Application de gestion (MAUI et clone web, session « new easymakemoney » / « appli degestion backend »)** : onglet « Assistance » : liste
des tickets (filtres état, canal, site), fiche avec la commande ou la vente liée, zone de réponse avec la proposition de l'IA, boutons
d'action (routes backend), prise en charge, historique, fermeture ; notifications.

**Caisse (posflutter)** : écran « Signaler un problème » (texte libre + contexte technique automatique), consultation des réponses.

**Application e-commerce mobile** : même formulaire que le site et suivi du ticket.

## Briques déjà en place à réutiliser

`Services/OrderService/OrderDeskService` (fiche d'une commande, actions permises), `OrderOperationsService` et `OrderReturnService`
(opérations confirmées), `SiteAssistantService` (outils proposés puis confirmés, paliers Aide IA / IA complète), `EmailSenderService` et
`translations/emails.{fr,en}.yaml`, `content_audit_log` (journal), `PosHealthService` et `pos_health_alert` (contexte caisse),
`AdminStepUrlResolver` (écrans), `SiteReadinessService` (étapes).

## Lot 1 livré : la boîte de demandes (12/11/2026)

Contrat validé par l'application de gestion (« new easymakemoney ») ; décisions du propriétaire du 11/11/2026. Code : `src/Support/`
(mapping Doctrine `AppSupport`), migration `Version20261222100000`, script `scripts/migrate_all_v2_support.sh`.

- **Tables** (par site) : `support_ticket` (référence `AS-000123`, sujet, état `new | open | waiting_customer | closed`, priorité, canal, client,
  commande ou vente liée, poste de caisse, agent, `due_at`, première réponse, fermeture, anonymisation), `support_message` (client | agent |
  système, note interne, état du courriel `sent | failed | logged`), `support_attachment` (stockage privé), `support_setting`.
- **Routes** (`/api/support`, `ROLE_SUPPORT` ou `ROLE_ADMIN`) : `GET|POST /tickets` (liste : `status`, `priority`, `channel`, `assigned=me|none|{id}`,
  `q`, `page`, `pageSize` ; création par l'agent : `subject`, `body`, `channel` agent | phone | counter | email, `priority`, `customer { userId } |
  { name, email }`, `orderId`, `locale`), `GET|PATCH /tickets/{id}` (fiche avec messages, pièces jointes et `orderSummary` ; transitions new → open
  | closed, open ↔ waiting_customer, tout → closed, closed → open, sinon 409 `INVALID_STATUS_TRANSITION`), `POST /tickets/{id}/messages` (JSON
  ou multipart `attachments[]` ; `internal` = note jamais envoyée ; `fromCustomer` = message du client consigné par l'agent, nouveau délai ;
  réponse de l'agent → courriel dans la langue du client et le ticket attend le client ; 409 `TICKET_CLOSED`), `GET /tickets/{id}/attachments/{id}`
  (fichier, `private, no-store`), `GET|PUT /settings` (écriture : `ROLE_ADMIN`). Erreurs : 404 `TICKET_NOT_FOUND`, `ATTACHMENT_NOT_FOUND`, 422
  `VALIDATION_FAILED { fields }`, 413 `ATTACHMENT_TOO_LARGE`, 403 `MODULE_INACTIVE` (module `support`, `feature: module.support`).
- **Délai de réponse** : `responseHours` (48 par défaut) comptées sur les jours ouvrés `businessDays` (lundi à vendredi), journées entières, fuseau
  du site, sans jours fériés (`Support/Services/BusinessClock`) ; 48 h ouvrées = 2 jours ouvrés ; seuls réglages du délai (les heures
  d'ouverture pourront s'ajouter sans changer la forme).
- **Courriels au client** : `outboundMode` `log` (défaut partout : rien n'est envoyé, une ligne de journal sans adresse ni texte) ou `send`
  (gabarit `emails/support_reply.html.twig`, fr / en) ; aucun envoi réel en test ; sur demo, rester en `log` ou n'écrire qu'à une adresse de test.
- **Pièces jointes** : images (JPEG, PNG, WebP, GIF) et PDF, vérifiés par le **contenu** (fileinfo), 10 Mo et 5 par message, stockage privé
  `var/storage/private_media/support/<site>/<ticket>`, nom aléatoire, jamais d'URL publique.
- **Résumé de commande en lecture** (`Support/Services/SupportOrderSummary`, liste blanche) : lignes, prix de vente, totaux, paiements, retours,
  livraison et suivi. Ni coût d'achat, ni marge, ni prix catalogue d'un prix manuel, ni prix fournisseur, ni note interne (test d'absence).
- **ROLE_SUPPORT** : `access_control` `^/api/support` ; connexion `platform=admin` acceptée (rôles dans le jeton) ; `Support/SupportScopeSubscriber`
  (après le pare-feu) : un compte Assistance **seul** reçoit 403 `SUPPORT_SCOPE_ONLY` sur toute route non publique hors du module, et les routes
  publiques lui sont servies comme à un anonyme (plusieurs routes ne demandent qu'un utilisateur connecté, et `User::getRoles` ajoute `ROLE_USER`).
  Quand c'est la règle d'accès du pare-feu qui refuse (route qui exige un rôle : `/api/profile`, `/api/gestion/…`), elle passe avant la
  garde : `ApiExceptionSubscriber` rend alors le même corps `SUPPORT_SCOPE_ONLY` (15/11/2026 ; avant : `FORBIDDEN`). La règle `^/api` reste
  telle quelle, sans `ROLE_SUPPORT` : c'est une seconde barrière. Preuve : `SupportRoleMatrixTest` balaie toutes les routes de l'API et
  vérifie le CODE de chaque refus (seule exception admise : une route publique qui refuse aussi un anonyme avec le même code, comme la
  caisse sans poste). EasyAdmin reste réservé à `ROLE_ADMIN`. **Aucun compte ROLE_SUPPORT ne doit
  être créé en production avant que l'application et le web le gèrent** (le rôle n'est pas proposé dans l'écran Utilisateurs).
- **Module payant** : droit gouverné `module.support` (`entreprise.is_support_active`, faux partout), garde « support » en mode plateforme live ;
  offre à créer quand le propriétaire en aura fixé le prix.
- **Conservation** : `app:support:retention` (simulation par défaut, `--apply`, `--tenant=`, timer à installer) anonymise tout ticket fermé depuis
  plus de 3 ans (nom, courriel, sujet, corps des messages, pièces jointes supprimées ; référence, dates, état, canal, priorité, délais gardés) ;
  un ticket rouvert repart de zéro à sa nouvelle fermeture.
- **Cheminement** : groupe `module.support` du cheminement du site (si le module est accordé) : `support.agent` et `support.settings`
  (bloquantes), `support.outbound`, `support.inbound` (améliorations ; adresse de réception au lot 2) ; `appScreen` `assistance-reglages`.
- Tests : `tests/Functional/Support/SupportApiTest.php`, `tests/Functional/Support/SupportRoleMatrixTest.php`.

## Lot SUPPORT-WEB livré : l'Assistance pour un compte qui n'ouvre qu'elle (14/11/2026)

Contrat validé par le propriétaire le 14/11/2026. Ce qu'il faut au web pour afficher l'Assistance à un compte `ROLE_SUPPORT` seul, et à
l'administrateur du site. Tests : `tests/Functional/Support/SupportWebApiTest.php`, `SupportRoleMatrixTest` (14 routes).

- **Connexion : plateforme `webadmin`** (`POST /api/login`, `POST /api/otp-verify`). Les rôles de `admin` (`ROLE_ADMIN` ou `ROLE_SUPPORT` ;
  `webadmin` accepte aussi `ROLE_SUPER_ADMIN`), **la session du navigateur de `web`** : mêmes cookies (`auth_token_<site>`,
  `refresh_token_<site>`, `XSRF-TOKEN_<site>`), renouvellement et déconnexion par cookies, écriture refusée sans l'en-tête
  `X-XSRF-TOKEN`. **Le corps de la connexion et de la vérification du code ne rend que `{ csrf_token }`** : le jeton d'accès et le jeton
  de renouvellement ne vivent que dans les cookies protégés (HttpOnly, Secure), hors de portée d'un script de la page (règle de toute
  session par cookies, `web` compris, depuis le 14/11/2026 : `TokenService::webSessionBody`, `docs/architecture.md`, « Plateformes de
  connexion »). `ROLE_USER_INTERNET` n'est pas demandé : un agent reste « Assistance seul ». Un
  compte client y reçoit 403 `PLATFORM_NOT_ALLOWED`, sans cookie. `web`, `admin` (application mobile de gestion : jetons dans le corps),
  `mobile` et `pos` ne changent pas. Code : `TokenService::COOKIE_PLATFORMS`, `AuthenticationService::ADMIN_PLATFORMS`.
- **`GET /api/support/me`** (agent ou administrateur) : `{ id, name, email, roles, supportOnly, site { code, name, locale, timezone },
  module { key, state: active | trial | inactive, endsAt, platformMode: demo | live }, screens, canEditSettings }`. Remplace `/api/profile`,
  `/api/validate-token` et `/api/site/features`, fermés à un compte Assistance seul ; ne porte rien d'autre du site. `platformMode` : en
  mode « demo » de la plateforme les routes répondent même quand `state` vaut `inactive`.
  `site.name` est le nom de la FICHE du site (`entreprise.name`), sinon celui que la plateforme lui connaît (`Services/Site/SiteName`,
  15/11/2026) : le même nom que l'objet du courriel d'invitation et que `siteName` de la vérification d'un lien.
- **Liste** `GET /api/support/tickets` : filtres `overdue=1` (non fermée, délai dépassé) et `awaiting=1` (non fermée, le dernier mot est
  au client), cumulables avec les autres ; toute autre valeur : 422 `fields.overdue | awaiting = INVALID_VALUE`. La réponse porte
  `counts { overdue, awaiting, unassigned }`, sur toute la boîte, quels que soient les filtres et la page.
- **`GET /api/support/agents`** : `{ agents: [{ id, name, role: support | admin, me }] }`, les comptes vérifiés à qui attribuer une demande
  (jamais un courriel ; le propriétaire de la plateforme n'y figure pas, sauf dans SA propre liste : le compte connecté y est
  toujours, avec `me: true`, puisqu'il peut s'attribuer une demande). **`PATCH /api/support/tickets/{id}` `assignedTo` accepte
  exactement les comptes de cette liste** (`SupportDirectoryService::assignable`) ; tout autre identifiant, une invitation en attente
  comprise, rend 422 `INVALID_AGENT`. Pour l'administrateur, en plus : `pending [{ id, name, expiresAt, expired }]`, les invitations
  en attente.
- **Commande par sa référence**, pour les deux rôles : `orderReference` dans `POST /api/support/tickets` (avec `orderId` : 422
  `NOT_WITH_ORDER_ID` ; inconnue : 404 `ORDER_REFERENCE_NOT_FOUND` ; portée par plusieurs commandes : 409 `ORDER_REFERENCE_AMBIGUOUS`
  avec `count` ; rien n'est créé) ; le client de la commande remplit la demande, sauf si `customer` est donné.
  `GET /api/support/orders/lookup?reference=` rend `{ order, orderSummary }` (la liste blanche de la fiche d'une demande) pour une
  référence exacte : ni recherche, ni liste.
- **Agents d'assistance** (`Support/Services/SupportAgentService`, administrateur du site seulement) :
  - `POST /api/support/agents { email, firstName, lastName, locale? }` → 201 `{ agent { id, name, email, role, pending }, invitation
    { emailSent, expiresAt, validHours } }`. Exige le **droit** du module (403 `SUPPORT_MODULE_REQUIRED` ; le mode « demo » ne suffit
    pas). Le compte créé a `ROLE_SUPPORT` et **aucun autre rôle** ; un compte existant n'est jamais transformé (409 `ACCOUNT_EXISTS`).
    Le jeton n'est rendu nulle part à l'administrateur.
  - `POST /api/support/agents/{id}/invitation` : un lien neuf, l'ancien ne vaut plus (409 `AGENT_ALREADY_ACTIVE`, 404 `AGENT_NOT_FOUND`).
  - `DELETE /api/support/agents/{id}` → `{ id, unassigned }` : l'agent perd l'accès ; son compte et ses messages restent, ses sessions
    sont closes, ses demandes en cours redeviennent sans agent. Seulement pour un compte Assistance seul (sinon 404).
  - Journal du site : `user_team`, `grant` (repère `invite`) et `revoke`.
- **Invitation** : le courriel (`emails/support_agent_invitation.html.twig`, fr / en) ne porte qu'un lien,
  `<adresse de l'espace d'administration>/admin/activer#token=<jeton>` : le jeton est dans le **fragment** de l'adresse (jamais envoyé
  à un serveur, donc dans aucun journal ; `docs/liens-espace-administration.md`). Il est à usage unique et vaut **48 heures**. Le web
  le lit, le retire de l'adresse, puis : `POST /api/password-reset/verify { token }` (facultatif, ne consomme rien) → `{ valid, kind:
  invitation | reset, expiresAt, siteName, emailMasked, passwordMinLength }` ; `POST /api/password-reset/confirm { token, password }` :
  le compte devient utilisable, le jeton ne vaut plus. Refus neutres : 400 `RESET_LINK_INVALID` (inconnu, expiré ou déjà utilisé, sans
  rien dire du compte), `FIELDS_REQUIRED`, `PASSWORD_TOO_SHORT` (8 caractères au moins) ; limite de débit du mot de passe oublié. Aucune
  session n'est rendue : l'agent se connecte ensuite par `webadmin`. C'est le mécanisme du mot de passe oublié (`resetToken`).
- **À renforcer (remarque du chef de projet, 14/11/2026 ; rien de changé dans ce lot)** : 8 caractères sans autre règle, c'est faible
  pour un compte qui lit les données des clients. Proposition à préparer pour la campagne de tests : longueur minimale plus grande pour
  les comptes de l'espace d'administration, refus des mots de passe les plus courants, code OTP pour les agents.
- **Mot de passe oublié d'un compte de l'espace d'administration** : `POST /api/password-reset/request { email, platform: "webadmin" }`
  → le courriel ouvre `/admin/activer#token=<jeton>` (fragment, 1 heure), comme l'invitation ; `kind: "reset"`. Sans `platform`, ou pour
  un compte client, la page du site comme avant (`docs/liens-espace-administration.md` § 4).
- **La règle gardée** : un compte Assistance seul ne voit jamais la gestion, les prix d'achat, la marge, la trésorerie ni les réglages du
  site ; `SupportScopeSubscriber` n'est pas assoupli, le lot n'ouvre que des routes sous `/api/support`.
- **Piège rencontré** : une arobase dans un bloc de documentation d'un contrôleur (un exemple d'adresse masquée) est lue comme une
  annotation et rend 500 sur toutes les routes ; écrire l'exemple dans un commentaire `//`.

## Transmettre un ticket à l'équipe technique (lot INC-3, 18/11/2026)

Un ticket qui est en fait un bogue de la plateforme se transmet d'un geste, par un agent du site : `POST /api/support/tickets/{id}/escalade`
`{ summary }` (20 à 2 000 caractères, écrit par l'agent, masqué) → 201 `{ platform { status: transmitted, answer: null } }`. Aucune copie du fil,
des pièces jointes ni du client ; un seul signalement ouvert par ticket (409 `ALREADY_ESCALATED`). La lecture d'un ticket porte `platform` :
`null`, ou `{ status: transmitted | fixed | wont_fix, answer }` (la réponse publique de la plateforme ; jamais sa note interne). L'agent reçoit un
courriel quand la plateforme répond. Détail : `docs/incidents-et-signalements.md`, § « Lot INC-3 ».

## « Aide et support » des applications du client (lot AIDE-APP, 18/11/2026, contrat validé par le chef)

Le client connecté de **Living Memory** ou du **commerce mobile** (pas la caisse) écrit à la boutique depuis l'écran « Aide et support » :
sa demande tombe dans la boîte de l'Assistance du site, que le commerçant traite dans la gestion web. Routes du compte client
(`^/api/account/support/`, `ROLE_USER_INTERNET`, code `src/Support/Controller/CustomerSupportController.php`,
`src/Support/Services/SupportCustomerService.php`) :

- `GET /api/account/support/availability` → `{ available, reason }` : jamais refusée ; `reason: "MODULE_INACTIVE"` quand le module n'est pas
  ouvert sur le site. L'application cache ou grise l'écran, avec le texte : « L'aide en ligne n'est pas proposée par cette boutique pour le
  moment. Vous pouvez la contacter par le formulaire de contact de son site. »
- `POST /api/account/support/requests` (JSON, ou multipart avec `attachments[]`) :
  - champs : `subject` (3 à 150 caractères), `message` (10 à 5 000), `category` (`order`, `delivery`, `payment`, `account`, `product`,
    `app_problem`, `other`), `orderId?` (une commande du client seulement, sinon 422 `ORDER_NOT_FOUND`), `appVersion?` (ajoutée en fin de
    message), `locale?` (`fr` ou `en`, sinon la langue du site) ;
  - 201 `{ request { reference, subject, category, status, unread, createdAt, lastMessageAt } }` ;
  - le ticket naît avec le canal `app`, l'**application d'origine lue dans le jeton** (champ `app` d'APPS-MEMBRES : `livingmemory` ou
    `boutique`, jamais du corps), la catégorie, le nom et le courriel du compte ;
  - refus : 422 `{ fields }` ; 409 `TOO_MANY_OPEN_REQUESTS` (3 demandes ouvertes au plus par compte) ; 429 `TOO_MANY_REQUESTS_TODAY`
    `{ scope: account | site }` (5 par compte, 200 par site et par jour) ou `RATE_LIMITED` (10 envois par quart d'heure et par compte,
    `Retry-After`) ; 413 `ATTACHMENT_TOO_LARGE` ; 403 `MODULE_INACTIVE` (garde des modules) ;
- `GET /api/account/support/requests` → `{ requests [...] }` : les 50 dernières demandes du compte, avec un état simplifié :
  - `new` et `open` → `in_progress` ;
  - `waiting_customer` → `answered` ;
  - `closed` → `closed` ;
  - `unread` : vrai si l'équipe a répondu (message non interne) depuis la dernière lecture du fil par le client.
- `GET /api/account/support/requests/{reference}` → `{ request, messages [{ id, from: customer | team, body, createdAt, attachments }] }` :
  jamais une note interne ; la lecture remet `unread` à faux (colonne `customer_read_at`) ; 404 `REQUEST_NOT_FOUND`, aussi pour la demande
  d'un autre client.
- `POST /api/account/support/requests/{reference}/messages` `{ message }` (ou multipart) → le fil ; la demande repasse « en cours » avec un
  nouveau délai ; 409 `REQUEST_CLOSED` pour une demande fermée.
- `GET /api/account/support/requests/{reference}/attachments/{id}` : une pièce jointe du fil (jamais celle d'une note interne).

Côté agent, la liste et la fiche portent `category`, `sourceApp` et `highlight` (vrai pour `app_problem` : le geste « Transmettre à
l'équipe technique » y est le plus utile), et la liste se filtre par `category`. Les réponses de l'équipe partent aussi par courriel si
l'envoi du site est réglé sur `send` (par défaut `log` : rien ne part) ; le fil dans l'application est donc la voie sûre. Aucune donnée
personnelle au-delà du compte et de ce que le client écrit ou joint. Migration `Version20270201100000` (colonnes `source_app`,
`category`, `customer_read_at` de `support_ticket`), script `scripts/migrate_all_v2_support_app.sh`. Essais :
`tests/Functional/Support/SupportCustomerAppTest.php`.

## Décisions restantes (lot 2 et suivants)

Prix et essai de l'offre « support » ; adresse de réception des courriels (domaine, MX, fournisseur : Mailgun, SES ou IMAP) ; le chat (lot 4)
consommera les crédits IA du site (décision du 11/11/2026).
