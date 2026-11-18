# PLAN ABO UNIVERSEL — formules à prestations (18/11/2026, AUDIT + PLAN VALIDÉ par le propriétaire avec ses 13 réponses, sans code)

Demande du propriétaire, relayée par le chef de projet. Ce document est un audit (ce qui existe, ce qui se réemploie, ce qui manque) et un
plan découpé en lots. **Plan et décisions du § 7 validés le 18/11/2026 ; chaque lot commence par un contrat validé par le chef.**

Le modèle voulu : une **formule d'abonnement** (prix, période, Stripe, comme aujourd'hui) livre une **liste de prestations** de quatre
types universels, créées sans code :

1. **séances planifiées** (yoga : capacité, lieu, créneaux) ;
2. **services à rendre** (« L'Intendant privé » : ménage, courses…) ;
3. **quota** (carte de 10 séances, 4 interventions par mois) ;
4. **accès à un module de la plateforme** (le branchement automatique des modules d'aujourd'hui, rendu modulaire).

Les formules par défaut d'Arkanoa (boutique de la plateforme, agences) sont des formules comme les autres : modifiables et détachables,
rien en dur.

## 0. Principes retenus

- **Une prestation est une définition du site** (« Ménage 3 h », « Yoga du mardi », « Accès Boussole ») ; la formule dit **combien** et
  **comment** l'abonné en profite (quantité par période, mode des dates, règles). Une même prestation sert à plusieurs formules.
- **Chaque droit à profiter est une occurrence** : une ligne par séance ou intervention due, créée au paiement de la période, qui vit
  jusqu'à « Fait », annulée, reportée ou perdue. Le **solde** est un compte d'occurrences, jamais un compteur qu'on décrémente : il se
  recalcule et se vérifie.
- **Le paiement fait naître les droits, une fois** : les occurrences d'une période naissent d'une facture payée, rattachées à cette facture.
  Rejouer la même facture ne crée rien (même règle que les commandes d'échéance `stripe_invoice_id`). Voir le § 5, lien avec PROVISION-3.
- **Seule la validation de l'administrateur fait foi** pour « Fait ». La confirmation ou la contestation de l'abonné est gardée à côté,
  sans rien décider.
- **Heure du magasin partout** (créneaux, veille, délais), stockage en UTC, rendu ATOM avec décalage ; un seul lecteur du fuseau.
- **Argent en centimes**, aucun nouveau montant en `float`.
- **Mémoires Vivantes hors champ** : ses rendez-vous (`reservation`) ne sont ni repris ni modifiés (décision du 16/11/2026).

## 1. Audit : ce qui existe

### 1.1 Abonnements Stripe de la boutique (réemployés tels quels pour la facturation)

- Entités `Subscription` et `SubscriptionPlan` (`src/Entity/`) ; services `src/Services/SubscriptionService/` ; cas d'usage
  `src/UseCase/SubscriptionUseCase/` ; écrans EasyAdmin « Formules d'abonnement » et « Abonnements » ; fiche `docs/boutique.md`.
- `subscription_plan` : produit porteur (`product_id`), textes par langue, `interval` et `interval_count`, `price` en centimes, `currency`,
  `trial_days`, `minimum_terms` (**affiché, jamais appliqué**), `stripe_price_id`, `active`, branchement `fulfillment_type`
  (`none`, `platform_offer`, `webhook` réservé) et `fulfillment_ref` (code de l'offre).
- `subscription` : statut (incomplete, trialing, active, past_due, paused, canceled), `current_period_end`, livraison, `payment_issue`,
  `fulfillment_target` (site servi, lot PROVISION-1).
- Webhooks : `invoice.paid` crée la **commande d'échéance** (une par facture, rejouable par `stripe_invoice_id`) ; impayé en `past_due`,
  résiliation au 4e échec ; `customer.subscription.*` recopie l'état.
- Pause : `pause_collection: void`, **sans date de reprise**. Changement de formule : même produit, au prorata.
- `SubscriptionLifecycleSubscriber` émet `SubscriptionStatusChangedEvent` à chaque changement de statut ou de période ; seul
  `PlatformOfferFulfillmentSubscriber` l'écoute (droits de modules).
- Courriels `SubscriptionMailer` (5 sujets, classés `subscription_notice`, indispensables).

**Manque** : une formule = un produit livré « première variante × quantité » ; ni prestations, ni quota, ni solde, ni agenda ; engagement
minimal non appliqué ; pause sans reprise datée ni effet sur ce qui est dû.

### 1.2 Réservations et créneaux : trois systèmes, aucun ne convient tel quel

| Système | Ce qu'il est | Réemploi |
|---|---|---|
| `Booking` + `BookingConfiguration` (`src/Services/Booking/`) | location d'un produit sur une plage, liée à une ligne de commande ; capacité = un **pool** par produit ; battement, horaires, dates proposées | **le calcul** de disponibilité et `BookingClock` (heure du magasin, changements d'heure couverts par `BookingTimezoneApiTest`) ; pas la table |
| `Reservation` (`src/Services/ReservationService/`) | rendez-vous de Mémoires Vivantes et cible des formulaires à étapes ; créneau **texte** (« 10:00-11:00 »), capacité 1, intervenant (`biographer_id`) | l'idée du **verrou de créneau** (`pg_advisory_xact_lock`) et la pièce `.ics` ; **ni la table ni le service** (MV hors champ) |
| Formulaires à étapes (`FormSubmission`, `docs/landingpage.md`) | demande publique qui écrit une `Reservation` | rien pour les abonnements |

**Manque** : un créneau daté avec capacité supérieure à 1, lieu et intervenant ; des créneaux récurrents ; une occurrence avec statut ;
le délai d'annulation calculé (`cancellation_policy` n'est qu'un texte) ; la liste d'attente (**aucune** dans le code) ; le rappel
la veille (aucun minuteur de rappel) ; le report.

### 1.3 Locations

Configurateur pas à pas (`/admin/location/configurer`), phrase « ce que verra le client » (`RentalSummaryService`), caution et retour au
comptoir (`PosRentalDeposit`, `RETURNED` + `is_finalized`, proche d'un « Fait »), occupation dans la gestion. **Réemploi** : la forme du
configurateur et de la phrase « ce que verra le client », pour configurer une formule. La location reste un achat ponctuel, hors du
modèle.

### 1.4 Droits des modules (PROVISION)

`src/Services/Platform/` : `EntitlementService` (sources `manual`, `trial`, `free`, `subscription`, `purchase`, `agency`),
`OfferFulfillmentService` (activated, renewed, unpaid, paused, resumed, cancelled → droits de référence `subscription:<site>:<id>`),
`platform_offers` (13 offres), `PlatformModeService` (`shop_tenant`, `shop_url`), lien prouvé `PlanLink/` (PROVISION-1). Seule la
boutique de la plateforme produit des droits. **C'est le type 4.**

### 1.5 Agences

`agency_plans` (une grille commune `essai`, `prices` vide), droits de source `agency` (`AgencyEntitlements`). Prix de gros, paliers et
relevés (AG-5, PROVISION-7 et 8) **non codés, prix non fixés**.

### 1.6 Briques transverses réemployables

- **Notifications** : `NotificationCatalog` + `NotificationGate` (niveaux, plafond de 3 par jour, rien avant 8 h du magasin), garde-fou
  `NotificationCatalogGuardTest` ; minuteur horaire `app:gestion:notifications:envoyer` (heure du magasin site par site).
- **Pièce `.ics`** : seulement dans le courriel de Mémoires Vivantes (`ReservationMailerService::buildIcsContent`) ; à refaire en petit
  service commun, sans toucher au fichier de MV.
- **Stockage privé** (`var/storage/private_media`, `/media/secure/{clé}`) et modèle de **preuve inaltérable** (`LossEvidence` : copie
  figée, empreinte SHA-256, déclencheur qui refuse la modification).
- **Patron de quota** : crédits IA (`PlatformAiCreditsService`, réservation atomique sous verrou puis consommation).
- **Gestion web** : la forme de « Ma journée » (`docs/journee-gestion.md`) et des vignettes d'accueil, pour « À faire aujourd'hui ».
- **Rôles** : aucun rôle « intervenant ».

## 2. Ce qui manque (synthèse)

1. **Modèle** : prestation (4 types), lien formule ↔ prestations (quantité, mode des dates, règles), occurrence, créneau à capacité,
   règle de récurrence, liste d'attente.
2. **Agenda** : trois modes de dates (client à la souscription, administrateur après, créneaux fixés), capacité sous verrou.
3. **Règles** : délai d'annulation calculé, report plafonné, pause datée, engagement minimal appliqué.
4. **Suivi** : « Fait » par occurrence, preuve facultative, confirmation ou contestation de l'abonné, intervenant assigné.
5. **Écrans** : création et configuration (EasyAdmin, gestion web) ; quotidien : « À faire aujourd'hui », « Fait », rendez-vous, fil de l'abonné, ajustements (gestion web ET application de gestion mobile, décision du propriétaire du 18/11/2026, § 8) ; prochaines dates, historique, solde (boutique et
   applications) ; formules et prestations (EasyAdmin, plateforme et agences comprises).
6. **Rappels et calendrier** : veille, place libérée, flux `.ics` signé.
7. **Type 4 rendu modulaire** : la prestation « accès module » remplace `fulfillment_type` / `fulfillment_ref`.

## 3. Modèle proposé (tables des bases de site, sauf mention)

| Table | Rôle | Colonnes principales |
|---|---|---|
| `benefit` | prestation du site | `kind` (`session`, `service`, `quota`, `module`), `names` / `descriptions` (JSON par langue), `duration_minutes`, `location`, `default_staff_user_id`, `offer_code` (type `module` seulement), `active` |
| `plan_benefit` | ce que la formule livre | `plan_id`, `benefit_id`, `quantity` par période (vide = sans limite, type `module`), `scheduling` (`none`, `client_at_subscription`, `admin_after`, `fixed_slots`), `slot_choice` (`client`, `admin`) pour `fixed_slots`, `cancel_deadline_hours`, `carry_over_max`, `proof` (`none`, `note`, `photo`), `position` |
| `benefit_slot_rule` | créneaux fixés à l'avance | `benefit_id`, jours de la semaine, heure locale, durée, `capacity`, lieu, intervenant, `from` / `until` |
| `benefit_slot` | un créneau daté | `benefit_id`, `rule_id` (vide = créneau ponctuel), `starts_at` / `ends_at` (UTC), `capacity`, lieu, `staff_user_id`, `status` (`open`, `cancelled`) |
| `benefit_occurrence` | un droit à profiter | `subscription_id`, `plan_benefit_id`, `invoice_id` (facture qui l'a fait naître), `period_start` / `period_end`, `slot_id` ou `scheduled_at`, `status` (`to_schedule`, `scheduled`, `done`, `cancelled`, `late_cancelled`, `missed`, `carried`, `expired`), `staff_user_id`, `done_by` / `done_at`, `customer_ack` (`confirmed`, `contested`) + commentaire, `proof_note`, `proof_media` + empreinte, `reminded_at` |
| `benefit_waitlist` | file d'attente d'un créneau | `slot_id`, `subscription_id`, `position`, `offered_at`, `offer_expires_at`, `status` |

Règles de calcul :

- **Naissance** : à chaque facture payée de la période, `quantity × subscription.quantity` occurrences `to_schedule` par prestation à
  quantité, plus celles reportées de la période d'avant (au plus `carry_over_max`). Unicité `(subscription_id, plan_benefit_id,
  invoice_id, rang)` : rejouer la facture ne crée rien.
- **Solde** d'une prestation = occurrences `to_schedule` + `scheduled` de la période en cours. « Utilisé » = `done`, `late_cancelled` et
  `missed`.
- **Fin de période** : les `to_schedule` non utilisées passent `carried` (jusqu'au plafond) ou `expired`. Une occurrence `scheduled`
  après la fin de période reste due.
- **Capacité** : réserver un créneau prend un verrou du créneau (`pg_advisory_xact_lock`), compte les occurrences `scheduled` et refuse
  au-delà (409 `SLOT_FULL`) ; la liste d'attente prend le relais.
- **Annulation** : avant `cancel_deadline_hours`, l'occurrence redevient `to_schedule` (rien n'est perdu) ; après, elle passe
  `late_cancelled` et compte comme utilisée.
- **Type `module`** : aucune occurrence. La prestation produit les droits de la plateforme (`OfferFulfillmentService`) et n'est permise
  que sur la boutique de la plateforme (et plus tard sur le site d'une agence, § 4, ABO-6).

## 4. Les lots, dans l'ordre (petits, chacun livrable seul)

| Lot | Contenu | Dépend de | Risque |
|---|---|---|---|
| **ABO-0** Socle des prestations et lectures de suivi | Tables `benefit` et `plan_benefit` (types `session`, `service`, `quota` ; `module` **réservé** jusqu'à ABO-5) ; EasyAdmin « Prestations », et dans la fiche d'une formule, la liste de ses prestations avec une phrase « ce que verra l'abonné » (modèle de `RentalSummaryService`) ; `GET /api/subscription-plans` rend `benefits[]` ; **lectures de suivi** pour la gestion web et mobile : `GET /api/site/abonnements/formules` (formules et compteurs d'abonnés par statut), `GET /api/site/abonnements/formules/{id}/abonnes` (paginé, filtre par statut), `GET /api/site/abonnements/{id}` (fiche d'un abonné : statut, période, impayé, prestations ; à faire, historique et solde s'y ajoutent à ABO-1) ; **aucun effet sur la facturation**. Migration de sites + script | — | faible |
| **ABO-1** Droits par période et solde | Occurrences nées de la facture payée (écouteur de l'événement « période payée » de PROVISION-3) ; fin de période (report plafonné, perte) par un passage quotidien ; solde et historique de l'abonné `GET /api/account/abonnements/{id}/prestations` ; vue de l'administrateur par abonné ; pause datée (Stripe `pause_collection` avec `resumes_at`) : pas de période donc pas de droits nés ; engagement minimal appliqué à la résiliation (si le propriétaire le veut, § 7) | ABO-0, PROVISION-3 | **à risque** (droits liés à l'argent) : relecteur neuf, mutations |
| **ABO-2** Agenda | Créneaux fixés (règles récurrentes, génération 8 semaines devant, capacité, lieu, intervenant) ; trois modes de dates : (a) le client choisit à la souscription, (b) l'administrateur attribue après, (c) créneaux fixés avec choix du client ou attribution ; verrou de capacité ; délai d'annulation calculé ; report par le client avant le délai ; routes client et administrateur | ABO-1 | **à risque** (capacité concurrente, fuseau) |
| **ABO-3** « Fait » et suivi | Bouton « Fait » par occurrence (administrateur, intervenant sur les siennes) ; « absent » ; preuve facultative (note, photo en stockage privé avec empreinte) ; courriel à l'abonné (sujet nouveau au `NotificationCatalog`) ; confirmer ou contester dans la boutique ; rôle **intervenant** (`ROLE_STAFF_PROVIDER`, ne voit que ses occurrences et le minimum de l'abonné) ; « À faire aujourd'hui » (`GET /api/site/abonnements/aujourdhui`, `ROLE_ADMIN`) et suivi par abonné, pour la gestion web et l'application de gestion mobile ; fil de l'abonné (répondre) | ABO-2 | moyen (données personnelles) |
| **ABO-4** Liste d'attente, rappels, calendrier | Liste d'attente par créneau, promotion automatique de la première personne et délai pour confirmer, puis la suivante ; rappel la veille à l'heure du magasin (minuteur horaire existant, sujet « suivi » ou « urgent » selon le § 7) ; flux `.ics` signé par abonné et par intervenant + pièce `.ics` dans les courriels (petit service commun, MV non touché) | ABO-2, ABO-3 | moyen |
| **ABO-5** Formules de la plateforme modifiables et détachables | Les formules de la boutique de la plateforme deviennent des formules à prestations `module` ; une commande `app:abo:formules-plateforme` (aperçu, puis application) les crée **une fois** depuis `platform_offers`, ensuite elles vivent seules : prix, textes, prestations modifiables ; « détacher » = retirer la prestation `module`, ou décocher « suivre le prix de l'offre » ; `fulfillment_type` / `fulfillment_ref` lus depuis la prestation (une seule vérité), puis retirés | ABO-0, PROVISION-1 à 3 | **à risque** (droits de modules) |
| **ABO-6** Formules des agences | Le site d'une agence vend des formules à prestations `module` qui ouvrent des droits de source `agency` sur les sites de ses clients, débités de son stock | PROVISION-7 et 8, prix d'AG-5 | **à risque** (argent), après décision des prix |

ABO-5 peut passer avant ABO-2 si le propriétaire préfère ouvrir la boutique de la plateforme d'abord (PROVISION-5) : il ne dépend que
d'ABO-0 et de PROVISION-1 à 3.

## 5. Branchement avec PROVISION-1 et PROVISION-3

- **PROVISION-1** (lien prouvé) : la règle « une formule branchée exige `fulfillmentLink` » devient, à ABO-5, « une formule qui contient
  une prestation `module` exige `fulfillmentLink` ». Le code de PROVISION-1 lit `SubscriptionPlan::hasFulfillment()` : à ABO-5, cette
  méthode lit les prestations, sans autre changement. Le site servi (`fulfillment_target`) reste sur l'abonnement.
- **PROVISION-3** (webhook solide), à coder en préparant ABO-1 :
  - le journal `stripe_event_log` et l'idempotence par événement s'appliquent à tous les abonnements, pas seulement aux formules branchées ;
  - PROVISION-3 émet un événement **« période payée »** (`SubscriptionPeriodPaidEvent` : abonnement, facture, début et fin de période),
    **une seule fois par facture**, après le traitement de `invoice.paid` ; ABO-1 l'écoute pour faire naître les occurrences, avec la
    même file de reprise en cas d'échec ;
  - la réconciliation nocturne recalcule ce qui est **voulu** (droits de modules aujourd'hui ; occurrences nées par facture à ABO-1) sans
    rejouer d'événement, et ne retire jamais ce qui vient d'une autre origine ;
  - « une fin de période ne recule jamais » protège aussi le calcul des périodes d'ABO-1.
- **PROVISION-2** (règle des sources) reste nécessaire à ABO-5 et ABO-6 : un droit `agency` ou `manual` n'est jamais retiré par une
  formule.

## 6. Risques

| Risque | Parade |
|---|---|
| Deux vérités pour le branchement (`fulfillment_type` et prestation `module`) | `module` réservé jusqu'à ABO-5 ; à ABO-5, la colonne est lue depuis la prestation, puis retirée |
| Droits nés deux fois ou jamais (rejeu, désordre des webhooks) | naissance par facture avec unicité ; PROVISION-3 d'abord (journal, ordre, file de reprise, réconciliation) |
| Capacité dépassée sous accès simultanés | verrou par créneau et compte sous transaction ; essai à deux connexions |
| Fuseau et changement d'heure (créneaux récurrents, veille) | règles en heure locale, créneaux générés en UTC par `BookingClock` ; essais aux deux changements d'heure |
| Données personnelles vues par l'intervenant | rôle propre, occurrences assignées seulement, prénom et adresse de l'intervention, rien d'autre |
| Preuve photo (vie privée, conservation) | stockage privé, empreinte, durée de conservation à fixer (§ 7), jamais publique |
| Plafond de 3 courriels par jour (NOTIF) qui retient un rappel | rappel classé selon la réponse du § 7 ; essai du plafond |
| Mélange avec Mémoires Vivantes | tables et services neufs ; `reservation` et son courriel non touchés |
| Pause et périodes Stripe | pause par `pause_collection` avec `resumes_at` ; aucune facture, donc aucun droit né ; occurrences déjà planifiées pendant la pause selon le § 7 |
| Carte de 10 séances = paiement unique, pas un abonnement | traitée à part (question § 7) : soit formule à une seule période sans renouvellement, soit produit de la boutique qui crée un quota |

## 7. Décisions du propriétaire (18/11/2026 : les 13 recommandations validées telles quelles, relayées par le chef)

Ce sont des **valeurs par défaut**, réglables par formule ou par prestation là où c'est indiqué.

1. **Délai d'annulation** : 24 h avant, réglable par prestation (`cancel_deadline_hours`). Une annulation tardive ou une absence compte
   comme utilisée (`late_cancelled`, `missed`).
2. **Report** : au plus le quota d'une période (`carry_over_max`, réglable par prestation de la formule), valable une période de plus, puis
   perdu (`expired`).
3. **Pause** : 3 mois au plus, une fois par an (réglable par formule) ; les dates déjà planifiées pendant la pause sont annulées sans perte
   (elles redeviennent à planifier).
4. **Résiliation** : ce qui est payé reste utilisable jusqu'à la fin de la période payée, puis perdu. Engagement minimal
   (`minimum_terms`) appliqué quand la formule en a un : résiliation possible seulement après N périodes.
5. **Dates choisies à la souscription** : places retenues 30 minutes pendant le paiement, libérées s'il échoue.
6. **Contestation d'un « Fait »** : trace visible de l'administrateur et demande d'Assistance ouverte automatiquement, sans remboursement
   automatique.
7. **Liste d'attente** : 2 h pour confirmer une place libérée, ou jusqu'à 1 h avant la séance ; plus de promotion automatique à moins
   d'1 h du début.
8. **Intervenant** : compte du site avec un rôle propre ; il ne voit que ses interventions, le prénom et l'adresse de l'intervention, sans
   le téléphone de l'abonné.
9. **Preuve du service** (note ou photo) : visible de l'abonné, conservée 12 mois.
10. **Rappel la veille** : à 18 h du magasin, par courriel et notification de l'application, classé « urgent » (il passe même au niveau
    « Essentiel »).
11. **Carte de 10 séances** : paiement unique valable 6 mois, vendu comme une formule à une seule période, sans renouvellement.
12. **Au-delà du quota** : refus simple pour commencer ; la séance supplémentaire payante à l'unité viendra plus tard.
13. **Ordre des lots** : ABO-0, ABO-1, ABO-2, ABO-3, ABO-4, puis ABO-5 ; ABO-6 après les prix des agences. Avant ABO-0 : PROVISION-1 et
    PROVISION-3 (avec l'événement « période payée ») ; le contrat d'ABO-0 est validé par le chef avant le code.

## 8. Responsabilités

- **Backend (ce dépôt)** : tout le modèle, les règles, les routes, les courriels, les minuteurs, EasyAdmin.
- **Next (boutique et gestion web)** : affichage des prestations d'une formule, choix des dates, solde, historique, confirmer ou
  contester ; « À faire aujourd'hui » et suivi par abonné.
- **Partage des écrans** (décision finale du propriétaire, 18/11/2026) :
  - **EasyAdmin** : création et configuration (formules, prestations, créneaux, règles d'annulation, de pause et de report, modules) ;
  - **gestion web ET application de gestion mobile (MAUI, module « Abonnements » dans ses onglets principaux)**, par les MÊMES routes
    `/api/site/abonnements/...` : le suivi et le quotidien. La liste des formules avec leurs compteurs, puis les abonnés d'une formule ;
    la fiche d'un abonné (ce qu'il y a à faire, historique, solde) ; « À faire aujourd'hui », « Fait » en un clic avec note ou photo
    facultative, rendez-vous, réponses, ajustements (dates, pause, report) ;
  - **abonné** : l'e-commerce web et l'application de commerce mobile.
- **Routes du quotidien : `/api/site/abonnements/...`, `ROLE_ADMIN`**, utilisables avec le jeton du web comme avec celui de
  l'application de gestion. Pourquoi pas `/api/gestion/...` : ce préfixe est gardé par le module payant `app.management` ; un commerçant
  qui vend des abonnements sans l'application de gestion doit pouvoir tenir son quotidien sur le web. Les abonnements relèvent du module
  Boutique (comme `/api/subscriptions`) : les routes sont gardées par lui, et rangées au registre des écrans par une règle explicite
  (écran « abonnements », famille de la boutique), jamais par le préfixe. L'intervenant (ABO-3) y accède avec son propre rôle, borné à
  ses occurrences.
- **Fil de l'abonné** (ABO-3) : un fil de messages rattaché à l'abonnement, sans dépendre du module Assistance ; une contestation ouvre
  en plus une demande d'Assistance quand ce module est actif (décision 6).
- **Applications du client** (commerce mobile) : prochaines dates, historique, solde, confirmer ou contester, par les mêmes routes que la
  boutique web ; contrat écrit à chaque lot.
- **Chaperon** : migrations, minuteurs, bascules.

## 9. Volet IA (règle permanente du propriétaire du 18/11/2026 : chaque lot prévoit ses outils de l'IA)

L'IA doit pouvoir faire le maximum de ce que chaque lot apporte, par l'**assistant du site (IA-A)** pour le commerçant
(`/api/site/assistant`, même geste « proposer puis Confirmer », 15 minutes, mêmes unités) et par l'**assistant de la plateforme (IA-C)** et
**de l'agence (IA-B)** pour leurs formules (ABO-5, ABO-6).

Règles communes :

- **Mêmes accès que l'écran** : un outil n'est donné au modèle que si l'écran correspondant est permis (`ROLE_ADMIN` du site, module
  Boutique actif : `SiteAssistantTools::MODULE_OF`) ; inventé quand même, il est refusé. L'intervenant (ABO-3) n'a pas l'assistant : il
  lirait tout le site.
- **Lectures** données au modèle comme outils ; **actions** toujours proposées (aperçu et résumé), puis confirmées par l'administrateur,
  appliquées par le service de l'écran avec ses validations et ses codes de refus, tout relu au moment de la confirmation ; trace
  `site_assistant` / `confirm` au journal du site.
- **Aucune donnée personnelle au modèle** : un abonné y est désigné par son numéro d'abonnement et sa formule, jamais par son nom, son
  courriel, son téléphone ni son adresse. L'écran lié (lien rendu par l'outil) montre l'identité à l'administrateur. « Qui est en impayé ? »
  rend donc la liste des abonnements en impayé (numéro, formule, montant dû, essai), chacun avec son lien.
- **Textes lus = données** : noms et descriptions des prestations, notes, preuves, messages des abonnés (ABO-3) sont des données, jamais
  des consignes ; toute adresse de courriel ou numéro de téléphone qu'ils contiennent est masqué avant l'envoi au modèle. **Essai
  d'injection** à chaque lot qui lit un texte saisi par quelqu'un d'autre que l'administrateur.
- **Jamais par l'IA** : un remboursement, la décision sur une contestation (elle la résume, l'administrateur tranche), la preuve photo, un
  prix appliqué sans confirmation.

| Lot | Lectures (outils) | Actions (proposées, puis confirmées) | Exemples de demandes |
|---|---|---|---|
| ABO-0 | `get_subscription_plans` (formules, prestations, phrase « ce que verra l'abonné », compteurs par statut) ; `get_benefits` ; `get_plan_subscribers` (numéros, statut, fin de période, impayé : montant et essai) ; `get_subscription` (fiche sans identité) | `propose_benefit` (créer ou modifier une prestation) ; `propose_plan` (créer une formule et ses prestations : prix en centimes, période, quantité, mode des dates, règles par défaut du § 7 ; produit porteur existant, ou un produit d'abonnement créé par le serveur) ; `set_plan_benefits` (prestations d'une formule) | « crée-moi une formule yoga 2 séances par semaine à 60 $ par mois » ; « qui est en impayé ? » ; « combien d'abonnés a la formule Ménage ? » |
| ABO-1 | `get_subscription` complété (à faire, historique, solde, report) | `propose_pause` (dates, dans les limites du § 7) ; `propose_resume` ; `propose_carry_over` (reporter des séances, dans la limite) ; `propose_cancel_subscription` (fin de période ; engagement minimal respecté ; en rouge, comme l'argent) | « mets l'abonné 42 en pause jusqu'au 15 janvier » ; « combien de séances reste-t-il à l'abonné 42 ? » |
| ABO-2 | `get_slots` (créneaux, places restantes) ; `get_to_schedule` (occurrences à planifier) | `propose_slot_rule` (créneaux récurrents : jours, heure, capacité, lieu, intervenant) ; `propose_assign_date` (une date ou un créneau pour une occurrence ; capacité vérifiée à la confirmation) ; `propose_move_occurrence` (avant le délai d'annulation) | « crée les créneaux yoga mardi et jeudi à 18 h, 12 places » ; « attribue une date à l'abonné 42 » |
| ABO-3 | `get_today` (« À faire aujourd'hui » : occurrences et séances du jour, sans identité) ; `get_subscription_thread` (fil de l'abonné, textes masqués) ; `get_contestations` | `propose_mark_done` (une occurrence, note facultative) ; `propose_mark_session_done` (une séance collective : toutes les présences, sauf les absents désignés) ; `propose_reply` (réponse rédigée dans le fil, lue avant l'envoi) | « qu'est-ce que j'ai à faire aujourd'hui ? » ; « marque la séance de 18 h comme faite, sauf l'abonné 42 absent » ; « réponds à l'abonné 42 que la séance est déplacée » |
| ABO-4 | `get_waitlist` | `propose_promote_waitlist` (proposer la place libérée à la personne suivante) | « qui attend une place jeudi ? » |
| ABO-5 (IA-C) | formules de la plateforme et leurs prestations `module`, suivi des offres | `propose_platform_plan` (prix, textes, détacher une formule de son offre) ; confirmation par le super administrateur | « baisse la formule Boussole à 49 $ pour les nouveaux abonnés » |
| ABO-6 (IA-B) | formules de l'agence, stock | `propose_agency_plan` ; attribution depuis le stock (confirmée par le responsable de l'agence) | « crée une formule site + boutique pour mes clients » |

Trop risqué, donc absent : modifier le prix d'abonnements EN COURS (Stripe le fait au prorata ; reste un geste de l'écran), résilier tout de
suite (seule la résiliation en fin de période est proposée), toute action sur plusieurs abonnés à la fois au-delà de 50 (aperçu nominatif
impossible sans identité).
