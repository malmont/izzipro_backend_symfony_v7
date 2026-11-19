# Audit : branchement automatique des modules (PROVISION)

Date : 18/11/2026. Demande du propriétaire, relayée par le chef de projet. Lecture seule du code en production (`dev`, eb549ca9 ;
rien ne change avec f808a663). Aucun essai, aucune requête SQL : deux réglages de la base maître (`platform_settings.shop_tenant` et
`shop_url`) n'ont donc pas été lus et sont notés « non vérifié ».

## Résumé en 10 lignes

1. Le chemin 1 (achat direct) est en grande partie **codé** (console de pilotage, lot 3 bis du 04/11) : une formule d'abonnement
   « branchée » sur une offre accorde, prolonge, met en grâce, suspend et retire les droits d'usage.
2. La **boutique de la plateforme n'existe pas encore** comme site : le code attend un site désigné (`shop_tenant`) ; la procédure de
   mise en place est notée « à faire » dans `docs/console-pilotage.md`.
3. Le webhook Stripe est signé. Son idempotence tient à un effet de bord (un événement rejoué ne change rien, donc rien n'est émis) :
   aucun identifiant d'événement n'est gardé.
4. **Faille principale** : l'acheteur tape le code du site à servir (`fulfillmentTarget`) ; le serveur vérifie seulement que ce site
   existe, jamais que l'acheteur l'administre.
5. Un droit accordé **remplace** tout droit vivant de la même fonctionnalité, quelle que soit sa source (agence, accord de la plateforme,
   abonnement d'un autre).
6. Un échec de pose des droits est seulement écrit au journal ; Stripe a déjà reçu 200 et ne renvoie rien : aucune reprise.
7. Ni le remboursement ni le litige (chargeback) d'un abonnement ne touchent aux droits.
8. Acheter un forfait complet sans avoir de site : **manque** (la file de création d'AG-2 n'est ouverte qu'aux agences).
9. Le chemin 2 (agence revendeuse) **manque presque entièrement** : l'agence ouvre et ferme des modules gratuitement, sans limite, sans
   stock et sans prix (`agency_plans.prices` jamais lue).
10. Proposition : 8 lots, PROVISION-1 à PROVISION-8 ; les quatre premiers sécurisent l'existant et passent avant l'ouverture de la boutique.

## Chemin 1 : achat direct dans la boutique de la plateforme

**Où vit la « boutique de la plateforme ».** Aujourd'hui, un ensemble de pièces sans site :
- un catalogue d'offres dans la base maître (`platform_offers`, 11 offres, prix provisoires), lu publiquement par `GET /api/offers`
  (`src/Controller/PlatformController/PublicOffersController.php:19`) ;
- la boutique ordinaire d'un site, mode abonnement : `POST /api/subscriptions` (`src/Controller/SubscriptionController/SubscriptionController.php:37`) ;
- sur la formule (`SubscriptionPlan`), `fulfillmentType = platform_offer` et `fulfillmentRef = <code de l'offre>`.

Seul le site désigné par `platform_settings.shop_tenant` produit des droits (`src/Services/Platform/OfferFulfillmentService.php:105-111`) ;
tout autre site est ignoré et journalisé. « Mettre à jour mon forfait » (`/admin/mon-site/forfait`) redirige vers `shop_url` s'il est
réglé, sinon affiche une page d'attente (`src/Controller/Admin/SiteConsoleAdminController.php:233-243`).

**Comment l'acheteur est relié à SON site.** Il saisit un code de site (`fulfillmentTarget`) en souscrivant
(`src/UseCase/SubscriptionUseCase/ManageSubscriptionUseCase.php:36`) ; le serveur contrôle la forme et l'existence du site
(`src/Services/SubscriptionService/SubscriptionService.php:107-121`), rien d'autre. L'acheteur est un compte client du site boutique, sans
lien avec les administrateurs du site visé.

**Compte Stripe.** Les abonnements passent par le compte connecté (Express) du site boutique, avec la clé de la plateforme
(`src/Services/SubscriptionService/SubscriptionStripeGateway.php:273-285`).

| # | Maillon | Statut | Preuve | Explication |
|---|---|---|---|---|
| 1 | Paiement confirmé (webhook) | PARTIEL | `src/Controller/StripeController/StripeWebhookController.php:36-50, 93-101` ; `src/UseCase/SubscriptionUseCase/HandleSubscriptionWebhookUseCase.php:24, 62-80` | `/api/stripe/webhook`, signature essayée avec trois secrets (plateforme, Connect, direct), exigée pour les abonnements. Site lu de `metadata.tenant_code`. Commande idempotente par facture. **Manque** : journal des événements traités ; un ancien événement rejoué réécrit la fin de période. |
| 2 | Droit accordé sur le site de l'acheteur | EXISTE, avec une faille | `src/EventSubscriber/SubscriptionLifecycleSubscriber.php:35-74` ; `src/EventSubscriber/PlatformOfferFulfillmentSubscriber.php:26-33` ; `OfferFulfillmentService.php:54-66, 143-152` | Source `subscription`, référence `subscription:<site>:<id>`, fin = fin de période + grâce (7 jours). Mais le site visé n'est pas prouvé, et un abonnement en essai (`trialing`) accorde le droit sans paiement. |
| 3 | Module allumé | EXISTE | `src/Services/Platform/EntitlementService.php:102-118` ; `src/Services/Platform/EntitlementProjector.php:20-57` ; `src/EventSubscriber/ModuleAccessSubscriber.php:51-53` | La projection écrit `is_*_active` dans la fiche du site et vide son cache ; menus et fronts lisent ces colonnes. Le garde-fou ne bloque qu'en mode `live`. |
| 4 | Renouvellement | EXISTE | `SubscriptionLifecycleSubscriber.php` (`renewed`) ; `OfferFulfillmentService.php:54-58, 132` ; `EntitlementService.php:208-222` | `invoice.paid` avance la période, `extend()` pose la nouvelle fin ; un droit suspendu est rétabli puis prolongé. |
| 5 | Impayé ou résiliation | PARTIEL | `HandleSubscriptionWebhookUseCase.php:123-159` ; `OfferFulfillmentService.php:68-73, 86-90` ; `EntitlementService.php:228-245, 374-394` | Impayé : grâce de 7 jours comptée depuis le PREMIER échec, puis suspension par le balayage ; au dernier échec, annulation immédiate. Résiliation : le droit court jusqu'à la suppression de l'abonnement (fin de période), puis il est annulé, sans la grâce prévue. Remboursement et litige : rien (voir Risques). |
| 6 | Forfait complet sans site | MANQUE | `SubscriptionService.php:117` (`FULFILLMENT_TARGET_UNKNOWN`) ; `src/Services/Agency/AgencySiteRequestService.php:74-103` | Le site visé doit exister. La file de fabrication exige une agence ; la création par la plateforme (TYPE-1) est réservée au super administrateur. |

Essais existants : `tests/Functional/Platform/OfferFulfillmentTest.php` (4) et `EntitlementsTest.php`. Minuteur de balayage actif (06:10 UTC).

## Chemin 2 : agence revendeuse (modèle A, prix de gros)

| # | Maillon | Statut | Preuve | Explication |
|---|---|---|---|---|
| 7 | L'agence achète un lot de droits | MANQUE | `ops/sql/agency_master.sql:40-55` | Aucune route d'achat ni paiement. `agency_plans.prices` existe mais n'est jamais lue ; prix, paliers et relevé renvoyés au lot AG-5 (`docs/agences-et-import.md` § 3.7, § 6). |
| 8 | Le lot devient un stock | MANQUE | — | Rien ne compte des droits achetés ou disponibles ; seule limite : le nombre de sites (`max_sites`). |
| 9 | Attribuer un droit, le module s'allume | PARTIEL | `PUT /api/agency/sites/{code}/modules/{feature}` (`src/Controller/AgencyController/AgencyController.php:190`) ; `src/Services/Agency/AgencyEntitlements.php:121-147, 259-262` | Droit de source `agency`, SANS fin, gratuit, sans limite, sans débit ; module allumé par la même projection. Seulement sur les sites créés par l'agence. Ne touche pas un droit d'une autre source (`AGENCY_MODULE_HELD`). |
| 10 | Le retirer : retour au stock | PARTIEL | `AgencyEntitlements.php:148-155` | Le droit `agency` est annulé ; il ne revient nulle part (pas de stock). |

## Risques

| Risque | Aujourd'hui (preuve) | Danger |
|---|---|---|
| Acheteur qui vise le site d'un autre | Forme et existence du code seulement (`SubscriptionService.php:107-121`) ; l'octroi remplace le droit vivant (`EntitlementService.php:113-117`) ; la résiliation annule le nouveau droit (`OfferFulfillmentService.php:86-90`). | Souscrire (même en essai sans carte) puis résilier sur le site d'un tiers éteint le module du tiers : son droit d'origine a été annulé au passage. À fermer avant l'ouverture. |
| Double accord (deux sources) | Un seul droit vivant : chaque octroi annule les autres, toutes sources confondues. | Un client d'agence qui achète en direct perd le droit `agency` pour de bon à sa résiliation ; un accord manuel annule un droit d'abonnement encore facturé. Aucune règle de priorité. |
| Double accord simultané | Ni transaction ni verrou dans `grant()`, pas d'index unique des droits vivants (`ops/sql/platform_master.sql:17-34`). | Deux événements au même instant : deux droits vivants, ou aucun. Rare, silencieux. |
| Webhook rejoué | Pas de journal des événements ; un rejoué ne change rien, donc rien n'est émis. | Sans effet aujourd'hui, par chance plus que par conception. |
| Webhook dans le désordre | `invoice.paid` réécrit la fin de période sans comparer (`HandleSubscriptionWebhookUseCase.php:71-74`). | Une facture ancienne reçue après une neuve RACCOURCIT le droit. |
| Échec de pose des droits | Exception attrapée et journalisée seulement (`PlatformOfferFulfillmentSubscriber.php:30`, `SubscriptionLifecycleSubscriber.php:70`). | Le client a payé, le module reste éteint ; ni renvoi de Stripe, ni réconciliation. |
| Remboursement | `refundLatest()` ne touche pas l'abonnement (`SubscriptionService.php:277-283`) ; `charge.refunded` ne sert qu'à l'impression de Mémoires Vivantes. | Un abonnement remboursé garde son droit jusqu'à la fin de période. |
| Litige (chargeback) | `charge.dispute.*` non traité. | Le module reste ouvert pendant tout le litige. |
| Essai | L'essai de la plateforme est unique (`TRIAL_USED`) ; l'essai d'une formule Stripe ne l'est pas. | Une formule avec jours d'essai accorde un droit sans carte, répétable avec un autre compte client. |
| Agence suspendue | La suspension se propage aux droits (`AgencyEntitlements.php:165-226`). | Correct ; pas de stock à geler. Un site détaché garde ses droits `agency` sans fin. |
| Site supprimé | Pas de clé étrangère des droits vers `tenants` ; projection sur un site absent : journal, refus (`EntitlementProjector.php:22-26`). | Un abonnement continue d'être facturé et de poser des droits au nom d'un code disparu. |
| Mode demo / live | En `demo`, rien n'est bloqué ; un mode illisible vaut `demo` (`PlatformModeService.php:33-45`). | Acheter ou non revient au même en `demo` ; le passage en `live` doit précéder la vente. |

## Proposition de lots

| Lot | Contenu | Dépend de | Risque |
|---|---|---|---|
| PROVISION-1 | **Lien prouvé acheteur ↔ site** : « Mettre à jour mon forfait » émet un jeton signé et court (site, administrateur, durée), porté jusqu'à la boutique ; une formule branchée l'exige à la place d'un code tapé. Décider si une formule branchée peut avoir des jours d'essai. | — | À risque, relecture neuve |
| PROVISION-2 | **Règle des sources** : priorité écrite (abonnement, agence, accord, essai) ; un octroi met un droit d'une autre source en sommeil et le rend à la fin ; verrou par site et fonctionnalité ; index unique partiel des droits vivants. | décision du propriétaire | À risque, relecture neuve, mutations |
| PROVISION-3 | **Webhook solide** : journal des événements Stripe (identifiant unique) ; une période plus ancienne ne raccourcit jamais un droit ; échec de pose rejouable ; réconciliation planifiée avec Stripe ; alerte au super administrateur. | PROVISION-2 | À risque |
| PROVISION-4 | **Remboursement et litige** : `charge.refunded`, `charge.dispute.created|closed` sur un abonnement branché, selon les règles du propriétaire ; journal. | PROVISION-3 | À risque (argent) |
| PROVISION-5 | **Ouvrir la boutique de la plateforme** : procédure existante (site, formules, Stripe, `shop_tenant`, `shop_url`), alignement automatique du prix de la formule sur l'offre, répétition sur demo, mode `live`, message au front. | PROVISION-1 à 4 | moyen |
| PROVISION-6 | **Forfait complet sans site** : réservation d'un code et fabrication par la file commune (AG-2, TYPE-1) ; l'acheteur devient l'administrateur invité ; droits posés au site prêt ; échec : alerte puis remboursement selon la règle. | PROVISION-5 | À risque |
| PROVISION-7 | **Prix de gros et stock d'agence** : grille et paliers (`agency_plans.prices`), achat d'un lot payé à la plateforme (Stripe de la plateforme en attendant Connect), table de stock par agence et fonctionnalité (quantité, durée, paiement d'origine), journal des mouvements, écran de l'agence. | décisions de prix ; AG-5 à fusionner | À risque (argent) |
| PROVISION-8 | **Attribuer et reprendre depuis le stock** : `PUT /api/agency/sites/{code}/modules/{feature}` débite le stock (atomique) et pose un droit `agency` daté de la fin du lot (`stock:<id>`) ; le retrait recrédite ; reprise des droits `agency` sans fin existants ; gel du stock à la suspension. | PROVISION-2, PROVISION-7 | À risque, relecture neuve |

Ordre conseillé : 1, 2, 3, 4, puis 5 (ouverture), puis 6. Le volet agence (7, 8) peut avancer en parallèle après la décision des prix ;
8 exige 2.

## Volet IA : outils de l'assistant de la plateforme (IA-C) pour les paiements des offres (spécification du 18/11/2026)

Règle permanente du propriétaire (18/11/2026) : chaque lot prévoit ses outils de l'IA. L'assistant de la plateforme (IA-C, super
administrateur seulement) est dans la file du chaperon, après G-8 ; ces outils y seront intégrés. Ils reposent sur PROVISION-3
(`docs/console-pilotage.md`, « Paiements des offres » : `OfferPaymentsRepository`, `OfferReconciler`, `OfferFulfillmentService`).

- **Accès** : les mêmes que l'écran « Paiements des offres » de la page Plateforme : `ROLE_SUPER_ADMIN`, compte de la plateforme ;
  jamais donnés à l'assistant d'un site (IA-A) ni d'une agence (IA-B).
- **Lectures** (outils donnés au modèle) :
  - `get_offer_payments_state` : compteurs (file de reprise : nombre, dont en incident ; événements des dernières 24 h par état :
    `processed`, `ignored`, `failed`, `received` ; dernière réconciliation de nuit : vus, corrigés d'après Stripe, droits corrigés, échecs),
    et les lignes de la file (numéro, site boutique, numéro d'abonnement, échecs, prochaine reprise, classe de la dernière erreur) ;
  - `get_offer_events` (filtres : état, type, site, 50 au plus) : identifiant de l'événement, type, site, abonnement Stripe visé, dates,
    état, détail court, essais. Le journal ne garde aucun contenu d'événement : rien de personnel ;
  - `get_offer_subscription` (site boutique, numéro d'abonnement) : formule, offre, site servi, statut, fin de période, impayé (montant,
    essai), droits posés sur le site servi (fonctionnalité, source, état, fin) et l'écart calculé (`drift`), SANS l'identité de l'acheteur ;
  - `get_offer_incidents` : les incidents `offres/abonnement/<id>` (repris des outils d'INCIDENTS ci-dessous).
- **Actions** (proposées, puis confirmées par le super administrateur, appliquées par le service de l'écran) :
  - `propose_offer_retry` : « Rejouer » une ligne de la file (`OfferReconciler::retryOne`, sous le verrou de l'abonnement) ; l'aperçu dit le
    site, l'abonnement, l'état connu et les droits qui seront recalculés ; le résultat est relu et rendu.
- **Trop risqué, donc absent** : relancer la réconciliation de nuit entière (une commande planifiée, jamais un geste de conversation),
  corriger un droit à la main (geste de la grille des droits, hors de l'assistant), rembourser ou résilier un abonnement.
- **Données** : identifiants Stripe et codes de site seulement ; aucune donnée personnelle (le journal et la file n'en ont pas). Les
  détails et les classes d'erreur sont des données, jamais des consignes.
- **Exemples** : « y a-t-il des droits vendus non posés ? » ; « pourquoi l'abonnement 12 de la boutique est-il en reprise ? » ;
  « rejoue-le » ; « combien d'événements Stripe ont échoué cette nuit ? ».
- **Essais** (au lot IA-C) : outils absents hors super administrateur ; aucune identité dans les résultats ; « Rejouer » seulement après
  confirmation, sous le verrou ; injection : un détail ou une classe d'erreur contenant une consigne n'entraîne aucune action.

## Questions au propriétaire

1. Prix des offres : les prix provisoires du 04/11 (CAD par mois) sont-ils confirmés ? Un prix annuel ? Taxes en plus ?
2. Essai : seulement l'essai de la plateforme (30 jours, une fois par site), ou aussi celui d'une formule payante ? Carte exigée ?
3. Grâce après impayé : 7 jours depuis le premier échec (aujourd'hui) ou depuis la fin de période ? Tout marche-t-il pendant la grâce ?
4. Résiliation volontaire : arrêt à la fin de la période payée, ou fin de période + grâce ?
5. Remboursement : coupure immédiate ou à la fin de période ? Remboursement partiel ?
6. Litige : suspension immédiate ? Et si le litige est gagné ?
7. Priorité des sources : quand un client d'agence achète en direct, qui gagne ? Le droit de l'agence revient-il à la résiliation ? L'agence est-elle encore facturée ?
8. Forfait complet sans site : qui choisit le code ? Que faire si la fabrication échoue ?
9. Compte Stripe des forfaits : compte connecté du site boutique (code actuel) ou compte de la plateforme en direct ?
10. Agence : seuils des paliers, prix de gros par module, durée d'un droit du lot (mois ou année) ? Le lot se consomme-t-il à l'attribution ou chaque mois ?
11. Un droit retiré revient-il entier au stock, ou au prorata du temps passé ?
12. Agence suspendue : le stock est-il gelé ?
13. Sites rattachés à la main à une agence (non créés par elle) : l'agence peut-elle y attribuer du stock ?
14. Paiement de l'agence : par carte dans la plateforme dès PROVISION-7, ou relevé mensuel hors plateforme d'abord (plan AG-5) ?
