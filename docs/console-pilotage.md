# Console de pilotage des fonctionnalités

Chantier ouvert le 04/11/2026. But : voir et piloter d'un seul endroit tout ce qui s'active ou se règle sur un site, pour
l'administrateur du site (ce qu'il a le droit de régler) et pour le super administrateur (tous les sites, ce qui est facturé),
et préparer la vente de ces fonctionnalités par la boutique de la plateforme (essais, gratuité, suspension à l'impayé).
Cette fiche tient l'inventaire, la cible et l'état des lots. Rôles et grille admin / super admin : `docs/architecture.md`,
ligne « Rôles ».

## 1. Inventaire : où s'active ou se règle quoi (état au 04/11/2026)

| Niveau | Où | Ce qu'on y pilote | Qui |
|---|---|---|---|
| Plateforme | table `tenants` (base maître), `/setup/new-store`, `/admin/super-admin`, `/admin/app-keys`, `/admin/short-code` | existence du site, domaine propre, code court, compte propriétaire, clés des applications mobiles | super admin (clés et code court : admin du site) |
| Fiche Entreprise, onglet Applications | EasyAdmin | 8 interrupteurs : `isBoutiqueActive`, `isLandingPageActive`, `isBoussoleEsgActive`, `isMemoireVivanteActive`, `isPosActive`, `isMobileShopActive`, `isManagementAppActive`, `isLivingMemoryActive` | super admin (facturé) |
| Fiche Entreprise, onglet Entreprise | EasyAdmin, `PUT /api/entreprise/{id}` | identité, coordonnées, devise (`currency`, CAD par défaut), prix TTC (`pricesIncludeTax`), numéros de taxes, adresse du site | admin du site |
| Localisation | `Services/CaisseService/SiteLocaleResolver`, `StoreClock`, registre `site.*` | langue du site (`entreprise.locale` choisie, sinon déduite : Québec → fr, autre province → en…), fuseau horaire (`posTimezone` s'il est réglé, sinon province ou pays, sinon America/Toronto), pays (`commerce.siteCountry`, déduit de l'adresse) | admin du site par `PUT /api/site/features` (depuis le lot 2) ; lisibles en `commerce.*` de `GET /api/boutique-settings` |
| Fiche Entreprise, onglet Caisse | EasyAdmin, 13 panneaux, lus par `GET /api/pos/settings` | une centaine de réglages POS (fuseau explicite, fidélité, cartes-cadeaux, remises, retours, hors ligne, terminal, alertes, conservation) | admin du site, sauf commissions `posTerminalFee*Bp` et mode Stripe `posStripeMode` |
| Fiche Entreprise, onglet Site web et SEO | EasyAdmin, `PUT /api/entreprise/{id}` | suivi (pixel), formulaires reçus (courriel, conservation), textes légaux, SEO | admin du site |
| Réglages JSON de l'éditeur | `BoutiqueSetting` (`PUT /api/boutique-settings`), `LandingPageSetting` (`PUT /api/landingpage-settings`) | `commerce.guestCheckout`, `commerce.subscriptionsEnabled`, `commerce.taxProvider`, `commerce.currency`, charte ; pages, modèles de site | admin du site par l'éditeur ; JSON brut EasyAdmin réservé au super admin |
| Écrans dédiés | `StripeConfig`, `/admin/pos-stripe`, `EasyPostConfiguration`, `GooglePlacesConfig`, `SquareConfig`, `EmailConfiguration`, `AiCreditSetting`, `/admin/memoires/ai-quotas`, `AppProfile`, `/admin/mobile-theme`, `ReviewSetting`, `Tax`, `Currency` | services externes, commissions, crédits et consignes IA, quotas IA, marque des applications, avis, taxes, devises | selon la grille du 03/11/2026 |
| Produit | EasyAdmin produit, `BookingConfiguration`, `SubscriptionPlan`, combinaisons | modes de vente (vente, location, abonnement), configuration de location, formules, fiche véhicule, personnalisation | admin du site |
| Mémoires Vivantes | console du front (`/api/memoires/admin/*`) et EasyAdmin | types de livre, chapitres et rôles, questions, utilisateurs et invitations, quotas IA (`AiQuotaSetting`), tarifs (`PricingSetting` : grille d'impression par devise, produit « chapitre » des stores), paiements, revenus, utilisateurs mobiles | admin du site ; plafonds IA : super admin |
| Boussole ESG | EasyAdmin seulement | référentiels (questions, certifications, subventions, recommandations, ODD), comptes et entreprises ESG (`is_active`) | admin du site ; aucun réglage de module, aucun quota |
| Landing Page | éditeur, `AiCreditSetting` | consignes permanentes de l'assistant (admin), crédits mensuels (super admin), mémoire de l'assistant, modèles de site | admin du site / super admin |
| Création d'un site | `/setup/new-store` (`TenantSetupController`, jeton `APP_SETUP_TOKEN`) | identité, coordonnées, adresse ; **rien sur les modules, la langue, le fuseau, la devise ni les modes de vente** | super admin |

Constats :

- Aucune vue d'ensemble : « abonnement » dépend de la case produit, d'une formule active, de `commerce.subscriptionsEnabled`,
  de Stripe prêt et d'un transporteur à prix fixe ; personne ne voit ces conditions ensemble.
- Langue, fuseau et pays sont déduits et invisibles ; un site bilingue ou frontalier n'a pas de prise.
- Les activations de modules n'étaient pas appliquées par le serveur (lot 1 bis : garde-fou en mode `live`). Jusqu'au 04/11/2026,
  `PUT /api/entreprise/{id}` les acceptait d'un administrateur de site (corrigé, lot 1) ; de même les plafonds IA par
  `PUT /api/memoires/admin/ai-quotas` (corrigé, lot 1).
- Point ouvert : les tarifs Mémoires Vivantes (`PUT /api/memoires/admin/pricing`, grille d'impression et produit des
  stores) sont facturés par la plateforme mais réglables par l'administrateur du site ; décision à prendre.

## 2. Cible

**Mode de la plateforme (décision du 04/11/2026).** Table maître `platform_settings`, clé `mode` : `demo` (défaut) ou `live`.
En `demo`, aucun garde-fou de module ni de droit d'usage ne bloque un service : tout reste visible et réglable dans la console,
rien n'est restreint (essais du propriétaire comme avant la console). Le passage en `live` est un geste explicite du super
administrateur (`PUT /api/platform/settings`, onglet « Plateforme »). `Services/Platform/PlatformModeService`, cache 60 s.

**Droits d'usage plutôt qu'interrupteurs.** Table de plateforme `tenant_entitlements` (base maître) : `tenant_code`,
`feature_key`, `source` (`manual`, `trial`, `free`, `subscription`, `purchase`), `starts_at`, `ends_at` (nul = sans fin),
`status` (`active`, `grace`, `suspended`, `expired`, `cancelled`), `reference` (commande ou abonnement de la boutique
plateforme, plus tard), `granted_by`, `note`. Politique par fonctionnalité : essai autorisé et durée, gratuité permanente
autorisée, délai de grâce après impayé. Droit effectif = un droit `active` ou `grace` non expiré
(`EntitlementService::isEnabled`, cache Redis court) ; les colonnes `is_*_active` deviennent une projection synchronisée
pour ne pas casser les frontaux.

**Offres et prix (décision du 04/11/2026).** Table de plateforme `platform_offers` (base maître) : `code`, libellé fr/en,
`features` (une ou plusieurs clés du registre : une offre est une fonctionnalité seule ou un lot), `monthly_price_cents`,
`yearly_price_cents` (facultatif), `currency` (CAD par défaut), `trial_days`, `is_active`, `position`. Les prix sont
modifiables dans l'onglet « Plateforme » et par `GET`/`PUT /api/platform/offers` ; **des prix par défaut sont posés au
départ** (à affiner) : Landing Page seule, Boutique seule, Landing Page + Boutique, Caisse, Application de gestion (.NET
MAUI), Caisse + Application de gestion, Boutique mobile, Mémoires Vivantes, Living Memory, Boussole ESG, et un lot « tout
compris ». Un droit d'usage issu d'un abonnement porte l'offre souscrite (`offer_code`) ; la boutique plateforme vendra ces
offres comme des formules d'abonnement et enverra les événements de facturation (`billing-events`). Le catalogue public des
offres (`GET /api/offers`, public, lecture) servira aussi au formulaire de création et au bouton « demander » de « Mon site ».

**Offres par défaut posées le 04/11/2026 (CAD par mois, 30 jours d'essai, à affiner dans la console)** : Landing Page 29, Boutique
en ligne 59, Landing Page + Boutique 79, Application e-commerce mobile 29, Caisse 49, Application de gestion 39, Caisse +
Application de gestion 79, Mémoires Vivantes 49, Application Living Memory 29, Boussole ESG 49, Tout compris 199. Un droit
issu d'un abonnement court jusqu'à la fin de période **plus** le délai de grâce de la politique (7 jours par défaut) et porte la
référence `subscription:<site vendeur>:<id>`.

**Branchement sur le mode abonnement de la boutique (décision du 04/11/2026).** La boutique reste générique : une formule
d'abonnement (`SubscriptionPlan`) reçoit `fulfillmentType` (`none` par défaut, `platform_offer`, plus tard `webhook` pour qu'un
site branche ses propres services) et `fulfillmentRef` (code de l'offre, ou URL du webhook). Le cycle de vie des abonnements
émet des événements génériques (activé, renouvelé, impayé, annulé, remboursé) ; un seul abonné traite `platform_offer` : sur le
site de la plateforme, il traduit l'événement en droits d'usage du site client acheté (code du site saisi à la commande, ou
site créé à la volée) et suspend ou retire ces droits à l'impayé ou à l'annulation. Même logique que `billing-events`, déclenchée
de l'intérieur ; la route HTTP reste pour un vendeur externe. Un site client qui vend ses propres abonnements (`none`) n'est
pas concerné.

**Tel que construit (lot 3, 04/11/2026).** Fonctionnalités gouvernées : `FeatureCatalog::GOVERNED` (cinq modules et trois
applications, chacune projetée sur sa colonne `is_*_active`). Un seul droit vivant par fonctionnalité : accorder remplace le précédent
(`cancelled`, note « remplacé par le droit n° … »). Un droit échu est expiré à la lecture (`effective()`), le balayage quotidien ne sert
qu'aux courriels et aux sites qu'on ne lit pas. Écrire une activation sur la fiche Entreprise (EasyAdmin ou API, super administrateur)
crée ou annule le droit correspondant (`EntitlementFlagSync`), sans courriel. Amorçage sur les sites existants :
`app:entitlements:bootstrap` (un droit `manual` sans fin par indicateur vrai ou clé d'activation valide ; relevé du 04/11/2026 :
`memoiresvivantes` et `demo` reçoivent `app.living_memory` par leurs clés, l'indicateur passe à vrai). Événements journalisés
(`tenant_entitlement_events`) : granted, trial_started, expiring_7, expiring_1, expired, grace, suspended, resumed, cancelled,
extended ; courriels pour tous sauf cancelled et extended. Politiques par défaut : essai autorisé 30 jours, gratuité autorisée,
grâce 7 jours (`FeaturePolicyRepository::DEFAULTS`).

**Registre `FeatureCatalog`** : chaque fonctionnalité avec clé stable, libellé, niveau (plateforme, site, produit), qui
peut la changer, où elle est stockée, ses prérequis et une vérification d'état (prêt, incomplet, désactivé). Il alimente
les deux onglets EasyAdmin, l'API du site et le formulaire de création.

**Événements Messenger** : `EntitlementGranted`, `EntitlementExpiring` (J-7, J-1), `EntitlementExpired`,
`EntitlementSuspended`, `EntitlementResumed` → courriels fr/en (bienvenue, fin d'essai, suspension, rétablissement),
projection et purge du cache, coupure propre (caisse : sessions ouvertes signalées), préparation du site (contenu
d'exemple du module).

**Routes** :

- Plateforme (super admin, puis la boutique plateforme par jeton de service) : `GET`/`POST /api/platform/entitlements`,
  `PATCH /api/platform/entitlements/{id}` (prolonger, suspendre, reprendre, annuler), `GET`/`PUT
  /api/platform/features/{key}/policy`, `POST /api/platform/billing-events` (`order.paid`, `subscription.renewed`,
  `subscription.unpaid`, `subscription.cancelled`).
- Site (admin du site ; lu et écrit par le panneau « Commerce » de l'éditeur Next, qui devient le miroir de cette route) :
  `GET /api/site/features` (registre du site : `scope` `site` modifiable ou `platform` lecture seule, valeur, prérequis,
  état), `PUT /api/site/features` (langue, fuseau, pays, devise, TTC, modes de vente acceptés, paiement invité, abonnements,
  taxes, réglages boutique utiles), `POST /api/site/features/{key}/request` (demande d'une fonctionnalité plateforme :
  file dans la console, plus tard redirection vers la boutique plateforme).

**Onglets EasyAdmin** : « Plateforme » (super admin : grille sites × fonctionnalités avec source et échéance, actions
accorder / essai de N jours / gratuit / suspendre / prolonger, politiques, file des demandes, journal) et « Mon site »
(admin : sections Localisation, Modules avec échéance et bouton « demander », Commerce = le même formulaire que le panneau
du front, Caisse, Réservation, Avis, Assistant IA, Services externes, chacune avec état et lien vers l'écran détaillé).
Mémoires Vivantes garde sa console du front ; la section « Mémoires Vivantes » de « Mon site » n'y ajoute que l'état du
module et les liens. Boussole ESG reçoit son premier réglage de module par ce chantier.

**Formulaire de création** : étape « Votre activité » (pays → langue, fuseau et devise proposés ; modules souhaités ;
modes de vente visés) ; les choix facturés passent en « demandé », validés depuis l'onglet Plateforme ; le reste
s'applique au provisionnement. Essais et gratuité par site seulement (pas par utilisateur final).

## 3. Lots

| Lot | Contenu | État |
|---|---|---|
| 1 | Verrous API : activations de `PUT /api/entreprise/{id}` et plafonds de `PUT /api/memoires/admin/ai-quotas` réservés au super administrateur (403 `SUPER_ADMIN_REQUIRED`, `fields`, valeur inchangée ignorée) | fait le 04/11/2026 ; tests `EntrepriseApiSuperAdminGuardTest`, `AiQuotaTest` |
| 1 bis | Relevé des indicateurs réels par site (cohérents pour les modules ; `is_living_memory_active` faux sur `memoiresvivantes` qui utilise l'application : indicateurs d'applications non appliqués) ; garde-fou `ModuleAccessSubscriber` (403 `MODULE_INACTIVE` sur Mémoires, Boussole, assistant Landing, début du parcours Boutique : devis, intention de paiement, souscription et changement de formule, lire ou résilier un abonnement reste servi ; `order/create*` et `payment` servis pour finaliser un paiement autorisé, resserré le 05/11/2026 ; caisse exclue) ; mode de la plateforme `demo` / `live` (`platform_settings`, `GET`/`PUT /api/platform/settings`) | fait le 04/11/2026 ; tests `ModuleAccessGateTest`, `PlatformSettingsApiTest` |
| 2 | `FeatureCatalog` (26 entrées), `SiteFeatureStateService` (valeurs, effectif, prérequis, états), `GET`/`PUT /api/site/features` ; `entreprise.locale` explicite (`siteLocale`), `commerce.salesModes` ; lectures des réglages de la boutique rendues déterministes (première ligne par id) | fait le 04/11/2026 ; test `SiteFeaturesApiTest` ; message au front à envoyer (panneau Commerce) |
| 3 | Tables maîtres `tenant_entitlements`, `tenant_entitlement_events`, `platform_feature_policies`, `tenant_feature_requests` (`ops/sql/platform_master.sql`) ; `Services/Platform/EntitlementService` (accorder, essai, gratuit, suspendre, rétablir, annuler, prolonger, grâce, balayage), `EntitlementProjector` (projection SQL sur `is_*_active` + cache du site vidé), `EntitlementFlagSync` (fiche Entreprise → droits, EasyAdmin et API), `EntitlementChangedMessage` + `EntitlementChangedHandler` (courriels fr/en au contact du site, repli expéditeur puis premier administrateur), `app:entitlements:bootstrap` (amorçage idempotent depuis indicateurs et clés valides), `app:entitlements:sweep` (échéances, rappels J-7 / J-1 ; `ops/systemd/entitlements-sweep.{service,timer}`, installé le 05/11/2026 : chaque jour à 06 h 10 UTC) ; registre : `entitlement` et `request` sur les clés plateforme, `POST /api/site/features/{clé}/request` | fait le 04/11/2026 ; test `tests/Functional/Platform/EntitlementsTest.php` |
| 3 bis | Table maître `platform_offers` (11 offres par défaut, prix provisoires), `PlatformOfferRepository`, `GET /api/offers` (public), `GET`/`PUT /api/platform/offers/{code}` (super admin), `POST /api/platform/billing-events` (super admin, vendeur externe) ; `fulfillmentType` / `fulfillmentRef` sur `SubscriptionPlan` (EasyAdmin, DTO), `fulfillmentTarget` sur `Subscription` (exigé à la souscription d'une formule branchée) ; `SubscriptionStatusChangedEvent` émis par `SubscriptionLifecycleSubscriber` (Doctrine), `OfferFulfillmentService` + `PlatformOfferFulfillmentSubscriber` (activated, renewed, unpaid → grâce, paused → suspendu, resumed, cancelled) | fait le 04/11/2026 ; test `OfferFulfillmentTest` ; migration `Version20261114100000`, script appliqué |
| 4 | API super administrateur `/api/platform/*` : `GET sites` (grille sites × fonctionnalités, demandes), `GET`/`POST entitlements`, `PATCH entitlements/{id}` (suspend, resume, cancel, extend), `GET policies`, `PUT policies/{clé}`, `GET requests`, `PATCH requests/{id}` (grant | refuse), `GET events` ; codes 422 `VALIDATION_FAILED`, 404 `NOT_FOUND`, 409 `POLICY_FORBIDDEN` ; `UseCase/PlatformUseCase/ManagePlatformEntitlementsUseCase` partagé avec la page EasyAdmin `/admin/plateforme` (`PlatformConsoleAdminController`, menu Maintenance « Plateforme : pilotage », super admin) : mode demo / live, grille, accorder (manuel, essai, gratuit), suspendre / retirer / prolonger, demandes, politiques, offres et prix, journal | fait le 04/11/2026 ; tests `PlatformConsoleApiTest`, `PlatformConsolePageTest` |
| 5 | Page EasyAdmin `/admin/mon-site` (`SiteConsoleAdminController`, menu Entreprise « Mon site : pilotage », administrateur du site) : Localisation et Commerce (formulaires → `UpdateSiteFeaturesUseCase`, mêmes écritures que `PUT /api/site/features`), Modules et applications (droit d'usage, prérequis, bouton « Demander » → `RequestSiteFeatureUseCase`, partagé avec `POST /api/site/features/{clé}/request`), Services et quotas (état, lien vers l'écran) ; formulaire `/setup/new-store`, étape « Votre activité » (langue, fuseau, devise, fonctionnalités souhaitées, modes de vente) appliquée par `Services/Platform/TenantSetupFeaturesService` : essai gratuit selon la politique (courriel), sinon demande en attente, puis projection (les modules non demandés sont désactivés) | fait le 04/11/2026 ; tests `SiteConsolePageTest`, `TenantSetupFeaturesTest` |

## Cheminement de préparation du site (05/11/2026)

`Services/Platform/SiteReadinessService` calcule en SQL direct, pour n'importe quel site, une liste ordonnée d'**étapes** : socle du
site (identité, adresse, langue / fuseau / devise, expéditeur des courriels, logo), puis une ou plusieurs étapes par module ou
application **accordé** (Boutique : taxes, Stripe, premier produit, livraison, abonnements si activés ; Caisse : clé d'activation,
poste ; Mémoires : types de livre ; Boussole : questions ; applications : clé, code court), et en fin les fonctionnalités **non
accordées** en « Obtenir » (`optional`, ou `pending` si une demande est en attente ; action `shop` vers la boutique de la plateforme
quand `shop_url` est renseignée, sinon `request`). Chaque étape porte `status`, `where` (`backend` écran EasyAdmin, `editor`
éditeur du site, `platform`) et `action` (`screen` + clé d'écran, `registry` + clé du registre, `editor` + url, `request` | `shop`).
L'avancement (`percent`, `done`, `total`) ne compte que les étapes `done` et `todo`.

Où il s'affiche : `GET /api/site/readiness` (ROLE_ADMIN ; l'éditeur Next exécute les étapes `editor` sur place et renvoie vers
EasyAdmin ou le forfait pour les autres), carte « Préparer mon site » en tête de la page Mon site (bouton « Faire » par étape),
carte sur le tableau de bord EasyAdmin tant que tout n'est pas prêt, colonne « Préparation » de la grille de la page Plateforme.
L'assistant IA de configuration (à venir) proposera d'exécuter l'étape suivante. Test : `tests/Functional/Platform/SiteReadinessTest.php`.

## Assistant IA de configuration (05/11/2026)

`Services/SiteAssistantService/SiteAssistantService` (modèle Sonnet 5.5, client `LandingAiClientInterface`, un crédit IA du site par
échange, remboursé si l'IA échoue, mode `assistant` dans l'historique des crédits). Contexte donné à l'IA : le registre compacté, le
cheminement et la liste des écrans. Outils (`SiteAssistantTools`) : `get_settings`, `get_readiness`, `open_screen` (immédiats, sans
effet) ; `set_site_settings` (clés `site` du registre seulement : une clé plateforme est refusée à l'IA) et `request_feature`
**proposent** une action, gardée 15 min dans le cache et appliquée seulement par `POST /api/site/assistant/actions/{id}/confirm`,
par les mêmes cas d'usage que Mon site (`UpdateSiteFeaturesUseCase`, `RequestSiteFeatureUseCase`), donc validation, rôles et journal.
Dans EasyAdmin, la page Mon site embarque le panneau « Assistant de configuration » (`POST /admin/mon-site/assistant` et
`…/assistant/actions/{id}/confirm`, session + en-tête `X-CSRF-Token`, mêmes cas d'usage) avec les liens vers les écrans et les boutons
« Confirmer ».
Routes (`ROLE_ADMIN`) : `POST /api/site/assistant { messages: [{ role: user | assistant, content }], locale? }` → `{ reply, actions:
[{ id, tool, input, summary }], links: [{ label, screen }], readiness, credits, usage }` ; 422 `VALIDATION_FAILED`, 402
`AI_CREDITS_EXHAUSTED`, 429 `AI_TOO_MANY_FAILURES`, 503 `AI_UNAVAILABLE` ; `POST …/actions/{id}/confirm` → `{ tool, input, result,
readiness }`, 404 `ACTION_NOT_FOUND` ; `GET /api/site/assistant/credits`. Le front (éditeur Next, page Mon site à venir) affiche la
réponse, les liens (`screen` → écran du back-office, même vocabulaire que la page Mon site) et les actions avec un bouton
« Confirmer ». Jamais : paiements, forfaits, droits de la plateforme, autres sites. Test : `tests/Functional/Platform/SiteAssistantTest.php`.

## « Demander » une fonctionnalité : essai immédiat, puis demande (règle du 05/11/2026)

`RequestSiteFeatureUseCase` : si la politique de la fonctionnalité autorise l'essai et que le site n'en a jamais eu pour elle, le clic
ouvre aussitôt un essai gratuit (`trial`, durée de la politique, courriel) ; sinon une demande part à la plateforme. Mon site affiche
« Essayer N jours » ou « Demander », et toujours « Mettre à jour mon forfait » (`/admin/mon-site/forfait` : la boutique de forfaits
si `shop_url` est réglée, sinon une page d'attente). Le cheminement porte l'action `trial` (`days`, `shopUrl`) sur les étapes « Obtenir ».

## Demandes des sites et courriels (05/11/2026)

Quand un administrateur demande une fonctionnalité (bouton « Demander » de Mon site, `POST /api/site/features/{clé}/request`) :
accusé de réception au contact du site, avis au **super administrateur** (son compte existe sur chaque base de site : destinataire
lu dans la base du site demandeur) avec le site, la fonctionnalité et le message ; refus depuis la console → courriel au site.
Accorder envoie le courriel d'activation ou d'essai habituel. Tous ces textes citent la **boutique de la plateforme** (`%shop%`)
quand son adresse est renseignée, sinon invitent à écrire à la plateforme (clés `*_noshop`).

**Un seul site gouverne les autres (05/11/2026)** : clé `shop_tenant` de `platform_settings` (page Plateforme, carte « Mode », ou
`PUT /api/platform/settings { shopTenant }`) : le code du site qui porte la boutique de forfaits. `OfferFulfillmentService` ignore
(et journalise) tout abonnement branché payé sur un autre site ; les champs « Branchement sur un service » des formules ne sont
visibles que du super administrateur (`setPermission`). Tant qu'aucun site n'est désigné, aucune formule branchée ne produit de droit.

**Adresse de la boutique de la plateforme** : clé `shop_url` de `platform_settings` (`PlatformModeService::shopUrl()`), réglée dans
la page Plateforme (carte « Mode ») ou par `PUT /api/platform/settings { shopUrl }` (https, vide = aucune). Tant qu'elle est vide,
« Demander » ne mène qu'à la file de la console ; une fois renseignée, Mon site affiche « Choisir un forfait » à côté de « Demander »
et les courriels y renvoient.

## Quand la boutique de forfaits de la plateforme sera créée (à faire, dans l'ordre)

1. Créer son site avec `/setup/new-store` (module Boutique, mode de vente abonnement, abonnements activés dans le registre).
2. Dans EasyAdmin de ce site : un produit par forfait et une formule d'abonnement par produit, avec « Branchement sur un service »
   = *Offre de la plateforme* et le code de l'offre (`landing_boutique`, `pos_management`, `all`…) ; prix et essai cohérents avec
   la page Plateforme, onglet Offres (un alignement automatique du prix de la formule sur l'offre reste à écrire).
3. Stripe : compte connecté du site, `app:boutique:stripe-setup`, webhook Connect déclaré (procédure de `docs/boutique.md`).
4. Front Next de ce site : champ « code de votre site » (`fulfillmentTarget`) au paiement des formules dont `fulfillmentType`
   vaut `platform_offer` (422 `FULFILLMENT_TARGET_REQUIRED` | `FULFILLMENT_TARGET_UNKNOWN` sinon), et page d'accueil des forfaits
   lisant `GET /api/offers`.
5. Renseigner l'adresse de cette boutique dans la page Plateforme (`shop_url`) : Mon site et les courriels y renvoient.
6. Passer la plateforme en mode `live` (les garde-fous s'appliquent) ; vérifier que le timer de balayage tourne.
7. Facultatif : proposer les forfaits (offres) dans l'étape « Votre activité » du formulaire de création à la place des cases
   fonctionnalité par fonctionnalité ; rediriger « Demander » directement vers la boutique.

## Tenir cette fiche à jour

À chaque lot : l'état du tableau ci-dessus, l'inventaire s'il change, et la fiche du module touché (`docs/boutique.md`,
`docs/memoires-vivantes.md`, `docs/boussole-esg.md`, `docs/landingpage.md`, `docs/caisse.md`, `docs/architecture.md`).
