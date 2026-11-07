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

## Décisions à prendre avant le lot 1

Adresse de réception des courriels (sous-domaine de la plateforme ou boîte du site), prix et palier du module, règles de délai de réponse
(SLA affiché au client), conservation des tickets, et si le chat (lot 4) consomme des crédits IA du site.
