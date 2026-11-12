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
  Preuve : `SupportRoleMatrixTest` balaie toutes les routes de l'API. EasyAdmin reste réservé à `ROLE_ADMIN`. **Aucun compte ROLE_SUPPORT ne doit
  être créé en production avant que l'application et le web le gèrent** (le rôle n'est pas proposé dans l'écran Utilisateurs).
- **Module payant** : droit gouverné `module.support` (`entreprise.is_support_active`, faux partout), garde « support » en mode plateforme live ;
  offre à créer quand le propriétaire en aura fixé le prix.
- **Conservation** : `app:support:retention` (simulation par défaut, `--apply`, `--tenant=`, timer à installer) anonymise tout ticket fermé depuis
  plus de 3 ans (nom, courriel, sujet, corps des messages, pièces jointes supprimées ; référence, dates, état, canal, priorité, délais gardés) ;
  un ticket rouvert repart de zéro à sa nouvelle fermeture.
- **Cheminement** : groupe `module.support` du cheminement du site (si le module est accordé) : `support.agent` et `support.settings`
  (bloquantes), `support.outbound`, `support.inbound` (améliorations ; adresse de réception au lot 2) ; `appScreen` `assistance-reglages`.
- Tests : `tests/Functional/Support/SupportApiTest.php`, `tests/Functional/Support/SupportRoleMatrixTest.php`.

## Décisions restantes (lot 2 et suivants)

Prix et essai de l'offre « support » ; adresse de réception des courriels (domaine, MX, fournisseur : Mailgun, SES ou IMAP) ; le chat (lot 4)
consommera les crédits IA du site (décision du 11/11/2026).
