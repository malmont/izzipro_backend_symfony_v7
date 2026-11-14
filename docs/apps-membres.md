# Applications à membres — APPS-MEMBRES (contrat validé le 14/11/2026 ; lots M1 et M2 codés le 14/11/2026)

Un compte du site peut servir à plusieurs applications : la boutique, Mémoires Vivantes, la caisse. Jusqu'ici rien ne disait dans
laquelle un client était entré, ni quelles conditions il avait acceptées. Ce chantier le dit, **sans jamais ajouter un client à une
application à son insu** : une appartenance naît d'un geste du client (ou d'une invitation de l'équipe), et passer d'une famille
d'applications à une autre demandera une étape « Rejoindre » où il accepte les conditions de cette famille.

État : **M1 codé** (table, appartenances créées aux entrées, conditions et version, routes de lecture, famille dans le jeton, mode
« observer » : aucune connexion refusée) ; **M2 codé** (reprise de l'historique par commande, § 8 ; elle s'applique site par site,
sur accord). M3 (« Rejoindre », mode « exiger », quitter une famille) et M4 (listes filtrées) restent à faire. **M1 ne change rien pour un client ni pour un front qui n'a pas bougé.**

Code : `src/Security/AppFamilies.php`, `src/Services/AppMembershipService/`, `src/Entity/UserAppMembership.php`,
`src/Controller/AppMembershipController/`, `src/Command/BackfillAppMembershipsCommand.php`. Tests :
`tests/Functional/Security/AppMembershipApiTest.php`, `AppMembershipBackfillTest.php` (la reprise), et la caisse dans
`tests/Functional/Boutique/PosCustomerApiTest.php`.

## 1. Ce qui existait

- Un compte = une ligne de `user` par site, sans colonne par application. `signup_channel` dit par où le compte est né
  (`web_invite`, `web_register`, `mobile` = Living Memory, `mobile_shop`), vide avant le 16/10/2026 ; `web_register` ne dit pas si
  c'est la boutique ou Mémoires Vivantes.
- La connexion reçoit `platform` (`web`, `mobile`, `pos`, `admin`), qui ne sert qu'au contrôle de rôle et ne dit pas l'application.
- Aucune acceptation de conditions par compte ; seule la caisse garde un consentement daté et versionné (`pos_customer_consent`).
- Les rôles d'équipe (`App\Security\TeamRoles`, journal `user_team`) et la Boussole (comptes séparés, `esg_user`) sont hors de ce
  chantier.

## 2. Les familles (décision du propriétaire : trois familles, pas une appartenance par application)

| Famille | Applications | Ce que le front envoie dans `app` |
|---|---|---|
| `boutique` | boutique du site (web) et application mobile de la boutique | `web`, `mobile` (ou `boutique`) |
| `memoires` | Mémoires Vivantes (web) et Living Memory (mobile) | `memoires`, `livingmemory` |
| `pos` | client de la caisse | `pos` |

- **Le web et le mobile d'une même famille partagent l'appartenance et ses conditions.** « Rejoindre » est obligatoire **entre**
  familles, jamais à l'intérieur d'une famille.
- La liste est dans `AppFamilies` et nulle part ailleurs (`ALL`, `APPS`). **Règle recommandée : un front envoie le code de SON
  application** (`web`, `mobile`, `memoires`, `livingmemory`, `pos`), celui qu'il connaît déjà ; le serveur le traduit en famille
  et rend toujours la **famille**. Envoyer la famille elle-même est accepté, sans plus.
- Ne sont pas des familles à membres : la gestion et EasyAdmin (équipe seulement), la Boussole.
- **Un compte d'équipe n'est jamais membre** et ne passera jamais par « Rejoindre » : son accès vient de son rôle. Compte d'équipe
  = il porte `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`, `ROLE_USER_POS`, `ROLE_POS_MANAGER`, `ROLE_SUPPORT` ou un rôle d'équipe de
  `TeamRoles` (biographe, conseiller) : `AppFamilies::isTeam()`. Le super administrateur en fait partie.

## 3. L'appartenance

Table du site **`user_app_membership`** : une ligne par compte et par famille. Ce n'est pas un rôle.

| Colonne | Sens |
|---|---|
| `user_id` | le compte (clé étrangère, la ligne part avec lui) |
| `app` | la famille |
| `source` | d'où elle vient : `register`, `join` (M3), `invite`, `guest_checkout`, `pos_link`, `backfill` (M2) |
| `joined_at` | date d'entrée (celle de la commande pour un invité, de la plus ancienne trace pour une reprise) |
| `terms_version`, `terms_accepted_at` | version des conditions acceptées et date ; **vides quand aucune acceptation n'a été donnée** : on n'invente pas un consentement |

Règles, toutes dans `AppMembershipService` (point d'entrée unique) :

- Une appartenance est **créée une fois** et n'est jamais modifiée : une seconde demande ne change ni la date, ni la source, ni la
  version, et n'écrit rien.
- Chaque création ou retrait est au **journal du site** (`content_audit_log`, ressource `user_app`, action `grant` ou `revoke`,
  champs « famille, source » ou « famille, raison », l'état après ou avant).
- Jamais d'appartenance pour un compte d'équipe, un compte effacé, ni pour **un compte né d'une commande en invité tant que le
  client n'a pas choisi son mot de passe** (`password_set` faux), quelles que soient ses commandes, **reprise comprise**.
- L'effacement d'un compte (suppression par le client ou par l'administrateur) retire ses appartenances.
- Se connecter ne crée jamais d'appartenance.

## 4. Les conditions d'une famille et leur version

Aucun nouveau texte dans ce chantier : les textes existants.

- `boutique` : la **fiche Entreprise** du site (politique de confidentialité, conditions d'utilisation, mentions légales).
- `memoires` et `pos` : le **profil d'application** `memoires` ou `pos`, dont un champ vide prend celui de la fiche Entreprise
  (règle de `AppProfileService`). Les profils `livingmemory` et `mobile` gardent leur rôle d'image de marque ; ils ne portent pas
  les conditions de la famille.

**La version est calculée par le serveur** (`AppTermsService::version()`) : une empreinte de 16 caractères des textes enregistrés
de la famille, **toutes langues confondues**. Elle change dès qu'un texte change, dans n'importe quelle langue ; elle est la même
quelle que soit la langue demandée. Une famille sans aucun texte n'a **pas de version** (`null`) : il n'y a rien à accepter. Deux
familles qui affichent exactement les mêmes textes ont la même version.

`GET /api/apps/{app}/terms?locale=fr|en` (public) — `{app}` est un code d'application ou une famille :

```
{ app: "boutique", version: "3f9c…" | null, locale: "fr",
  privacyPolicy, termsOfUse, legalNotice, privacyPolicyUrl, updatedAt }
```

404 `APP_UNKNOWN` (`params.apps`, `params.families`) pour tout autre code. `updatedAt` et `privacyPolicyUrl` viennent du profil
d'application ; ils sont `null` pour la boutique.

**Format des textes.** `privacyPolicy`, `termsOfUse` et `legalNotice` sont rendus **tels qu'ils sont enregistrés**, sans
transformation, exactement comme `GET /api/entreprise` et `GET /api/app-profile/{app}` les rendent déjà : `null` quand le texte est
vide ; sinon du texte, qui peut contenir du HTML. Les textes de la fiche Entreprise écrits depuis l'éditeur du site sont limités
par `LegalTextPolicy` (titres, paragraphes, listes, tableaux, liens `<a href>` ; aucun style, script ni autre attribut) ; ceux
saisis dans EasyAdmin (fiche Entreprise, profils d'application) sont des zones de texte que le serveur ne filtre pas. **Un front
les affiche donc comme il affiche déjà ces textes : en HTML assaini de son côté**, avec les retours à la ligne d'un texte simple.
Les liens d'un texte sont ceux que l'auteur a écrits, sans réécriture (le serveur ne les rend pas absolus) ; `privacyPolicyUrl`,
lui, est une adresse absolue (`https://…`).

Pas de nouvelle demande d'acceptation quand les conditions changent, dans ce chantier : la version acceptée est gardée, ce qui le
permettra plus tard.

## 5. Les parcours

| Parcours | Ce que fait le serveur | Lot |
|---|---|---|
| **Inscription dans une application** (`POST /api/register` avec `app`) | Le compte entre dans la famille (`register`). `termsVersion` (facultatif) est gardé **s'il est la version en vigueur** ; sinon l'appartenance est créée sans acceptation. Sans `app` : aucune appartenance. | M1 |
| **Compte client créé par l'équipe** (Mémoires Vivantes, `POST /api/memoires/admin/users`) | Membre de `memoires` (`invite`), **sans** conditions acceptées ; il les acceptera à sa première connexion (M3). Un compte d'équipe créé de même n'est pas membre. | M1 |
| **Commande en invité** (`POST /api/order/create-guest`) | La commande crée un compte **sans mot de passe choisi** (c'est le fonctionnement d'avant ce chantier) : **aucune appartenance**. `termsVersion` (facultatif) est gardé sur la commande s'il est la version en vigueur de la boutique. | M1 |
| **Ce client choisit son mot de passe** (« mot de passe oublié ») | C'est la création de son compte : il entre dans `boutique` (`guest_checkout`) **à la date et à la version de sa première commande en invité**. Un mot de passe posé par l'équipe n'est pas ce geste : aucune appartenance. | M1 |
| **Fiche de caisse rattachée à un compte** (consentement `account_link`) | Le compte entre dans `pos` (`pos_link`), à la version du texte consenti et à la date du consentement. | M1 |
| **Le rattachement de caisse se défait** | L'appartenance `pos` du compte part avec lui, au journal (`revoke`). Trois cas : **retrait du consentement `account_link`** (`PATCH /api/pos/customers/{id}` avec `consents.account_link: false`) : le rattachement est défait (`user_id` vidé) et l'appartenance retirée **dans la même transaction**, raison `consent_withdrawn` ; la réponse montre la fiche sans compte rattaché. **Fiche effacée** à la demande du client : raison `pos_customer_erased`. **Fusion de deux fiches rattachées à deux comptes différents** : le compte de la fiche fusionnée perd son rattachement, raison `merge` ; si la fiche conservée n'avait pas de compte, elle reprend celui de la fiche fusionnée et l'appartenance survit. À la fusion, un consentement repris garde **sa version et sa date d'origine** : une fusion n'est pas un nouveau consentement. | M1 |
| **Connexion à une famille dont le compte n'est pas membre** | M1 : la session est rendue, la connexion est seulement **notée** (mode « observer », § 6). M3 : refus `APP_MEMBERSHIP_REQUIRED` avec les conditions et un jeton de passage de 10 minutes ; `POST /api/apps/join { joinToken, termsVersion, accepted: true }` crée l'appartenance (`join`) et rend la session ; `TERMS_VERSION_CHANGED` si les conditions ont changé entre-temps. | M3 |
| **Quitter une famille** sans supprimer son compte (retrait du consentement, Loi 25) | `DELETE /api/account/memberships/{app}`, journalisé ; il faudra « rejoindre » de nouveau. | M3 |
| **Compte de l'historique** | Reprise par commande, à part (§ 8). Une appartenance reprise est acquise pour l'accès ; l'acceptation sera demandée plus tard, sur décision. | M2 |

## 6. Comment le serveur sait la famille : le champ `app`

`app` est un champ **facultatif** de `POST /api/login`, `POST /api/otp-verify`, `POST /api/token/refresh` (dans le corps, que la
session soit web ou non) et `POST /api/register`. Il s'ajoute à `platform`, qui ne change pas.

**Règle de compatibilité : tant qu'un front n'envoie pas `app`, le serveur fait exactement ce qu'il faisait.** Une valeur inconnue
est ignorée de la même façon.

Avec `app` :

- **Le jeton d'accès porte la famille** : champ `app` du JWT (`boutique`, `memoires`, `pos`), en ajout ; les autres champs sont
  ceux d'avant. Le jeton de rafraîchissement ne garde pas la famille : **l'application la redit à chaque rafraîchissement**, sinon
  le nouveau jeton n'en porte pas.
- **Rafraîchissement d'une session ouverte avant M1** (jeton d'accès sans `app`) : `app` dans le corps de
  `POST /api/token/refresh` suffit, le nouveau jeton porte la famille. Le serveur ne compare avec rien : il n'a gardé aucune
  famille, il prend celle du corps à chaque fois. Une application peut donc commencer à envoyer `app` au rafraîchissement sans
  faire reconnecter personne.
- **Mode « observer »** (M1, le seul mode pour l'instant) : si le compte est un compte client qui n'est pas membre de cette
  famille, la session est rendue quand même et une ligne est écrite au journal de l'application :
  `[APPS-MEMBRES] observer : le compte N n'est pas membre de « famille » (login | otp-verify | refresh, site code) ; session rendue`.
  C'est ce que le mode « exiger » refusera. Compter ces lignes par site dit si un site est prêt.
- **Mode « exiger »** (M3) : par site, colonne `entreprise.app_membership_mode` (`observe` par défaut, déjà créée). On y passe un
  site seulement quand ses fronts sont prêts et sa reprise faite ; aucune session ouverte n'est coupée, le contrôle se fait à la
  connexion suivante.

### Le refus du lot M3 (forme prévue, pour que les fronts se préparent ; rien de cela n'existe en M1)

En mode « exiger », `POST /api/login` et `POST /api/otp-verify` (au moment où ils rendraient la session) répondent **403** :

```
{ code: "APP_MEMBERSHIP_REQUIRED", error: "APP_MEMBERSHIP_REQUIRED", message,
  app: "memoires",
  reason: "membership" | "terms",
  terms: { version, locale, privacyPolicy, termsOfUse, legalNotice, privacyPolicyUrl, updatedAt },
  joinToken, expiresAt }
```

- `reason: "membership"` : le compte n'est pas membre de la famille ; `"terms"` : il en est membre (invité par l'équipe) mais n'a
  pas encore accepté ses conditions. Dans les deux cas l'écran est le même : les conditions, un bouton.
- `terms` a la forme de `GET /api/apps/{app}/terms` ; `terms.version` est `null` quand la famille n'a aucun texte (rien à afficher,
  seulement le bouton).
- `joinToken` prouve que le mot de passe (et le code, s'il y en a un) vient d'être vérifié ; `expiresAt` (ISO 8601) : 10 minutes.
  `POST /api/apps/join { joinToken, termsVersion, accepted: true }` rend la session exactement comme une connexion ; 409
  `TERMS_VERSION_CHANGED` (avec les nouvelles `terms`) si la version a changé entre-temps.
- **`POST /api/token/refresh` ne rend jamais ce refus** : aucune session ouverte n'est coupée (décision du propriétaire) ; le
  contrôle se fait à la connexion suivante. Le rafraîchissement continue de noter, comme en mode « observer ».
- Ce code est distinct de `PLATFORM_NOT_ALLOWED` (rôle) et des refus de site. Le contrat de M3 confirmera cette forme avant le code.

## 7. Routes

Nouvelles (M1) :

- `GET /api/apps/{app}/terms?locale=` — public (§ 4).
- `GET /api/account/memberships` — le compte connecté :

```
{ team: false,
  memberships: [{ app, joinedAt, source, termsVersion, termsAcceptedAt }] }
```

  `team: true` pour un compte d'équipe, qui n'a aucune appartenance et n'en a pas besoin. De la plus ancienne à la plus récente.

Existantes, en ajout seulement (M1) : `app` à la connexion, à la vérification du code et au rafraîchissement ; `app` (toutes les
familles) et `termsVersion` à l'inscription ; `termsVersion` à la commande en invité. Aucune réponse existante ne change.

À venir : `POST /api/apps/join` et `DELETE /api/account/memberships/{app}` (M3) ; `GET /api/site/members?app=`, colonne et filtre
« Applications » d'EasyAdmin, `memberships` et `teamRoles` dans les lectures de profil, filtre `?app=` de
`GET /api/memoires/admin/users` (M4).

## 8. Migration et reprise

**Migration** `Version20261229100000` et `scripts/migrate_all_v2_apps_membres.sh`, additive et idempotente : la table
`user_app_membership` (unicité compte + famille, cascade avec le compte), `"order".terms_version`,
`entreprise.app_membership_mode`. **Aucune donnée existante n'est modifiée et aucune appartenance n'est créée par la migration.**

**Reprise de l'existant (lot M2, codé le 14/11/2026)** : une commande à part, jamais dans une migration.

```
php bin/console app:members:backfill <site>                          # simulation : aucune écriture, un rapport
php bin/console app:members:backfill <site> --apply --confirm=<site> # écrit, puis marque la reprise faite
```

`--apply` exige que le code du site soit redit (`--confirm`) : sans lui rien n'est écrit. `--json` rend le rapport en JSON. La
commande est rejouable : une appartenance existante n'est jamais modifiée, un second passage ne crée rien. Une appartenance reprise
porte la source `backfill`, la date de **la plus ancienne trace**, et **aucune version de conditions** ; seule la famille `pos`
garde la version et la date du **vrai** consentement `account_link` de la fiche rattachée (ce n'est pas un consentement inventé).
Chaque création est au journal du site, avec la liste des traces qui la fondent (`after_state.traces`). L'application d'un site
est une seule transaction : tout ou rien. Code : `AppMembershipBackfill` (`plan()` ne fait que lire, `apply()` écrit),
`UserAppMembershipRepository` (les requêtes des traces), `BackfillAppMembershipsUseCase`, `Command/BackfillAppMembershipsCommand`.

**Jamais repris** : un compte d'équipe, un compte effacé, un compte **sans mot de passe choisi** (même avec des commandes), un
compte déjà membre de la famille.

Traces retenues (un compte peut entrer dans plusieurs familles) :

| Famille | Trace (nom dans le rapport) | Date d'entrée |
|---|---|---|
| `memoires` | inscription par Living Memory (`signup_living_memory`) ; invitation par l'équipe (`team_invite`) | celle du compte |
| `memoires` | propriétaire d'un livre (`book_owner`) ; paiement de chapitre, quel que soit son état (`chapter_payment`) ; commande d'impression (`print_order`) | la plus ancienne |
| `boutique` | inscription par l'application mobile de la boutique (`signup_mobile_shop`) | celle du compte |
| `boutique` | commande du compte dont l'origine est `Ecommerce` ou `Mobile_app`, annulée ou remboursée comprise (`order`) ; avis (`review`) ; abonnement (`subscription`) ; panier de paiement (`checkout_session`) ; panier enregistré avec au moins un article (`cart`) | la plus ancienne |
| `pos` | fiche de caisse rattachée, ni effacée ni fusionnée, dont le **dernier** consentement `account_link` est accordé (`pos_link`) | celle du consentement |

Ne sont pas des traces : une vente de caisse (elle porte le compte du **caissier**) ; **une adresse seule** (elle n'a pas de date et
ne prouve pas la boutique : mieux vaut un « Rejoindre » de plus qu'une appartenance devinée) ; un panier vide ; `signup_channel`
vide ou `web_register` ; une simple connexion ; une commande sans origine connue. Le compte d'un livre peut être celui du
biographe : un compte d'équipe n'est jamais repris. Un compte sans trace reste sans appartenance : il passera par « Rejoindre »
une fois son site en mode « exiger ».

**Le rapport** ne porte que des nombres, aucun nom ni courriel : comptes du site et comptes écartés par raison (équipe, effacés,
sans mot de passe choisi) ; par famille, les déjà membres, les appartenances proposées, leur détail par trace (un compte est compté
une fois par trace qu'il porte) et les dates d'entrée extrêmes ; les comptes clients sans trace ni appartenance, dont ceux qui
n'ont qu'une adresse ; trois signalements que la reprise ne touche pas (fiches de caisse rattachées sans consentement en vigueur,
comptes sans mot de passe choisi qui ont des commandes, commandes de compte sans origine). Le détail compte par compte
(identifiant, famille, date, traces) est écrit dans `var/members-backfill/<site>-<date>-<mode>.json`, sur le serveur, hors dépôt.

**État du site « reprise faite »** : `entreprise.app_membership_backfilled_at` (migration `Version20261230100000`,
`scripts/migrate_all_v2_apps_membres_reprise.sh`), posée par `--apply` à sa première réussite. Tant qu'elle est vide, aucune règle
n'écarte un compte faute d'appartenance : les clients d'avant ce chantier n'ont pas encore la leur. Elle commande deux choses :

- **la caisse** (décision du propriétaire, vie privée) : une fois la reprise faite, la recherche de comptes web
  (`GET /api/pos/customers?q=`, courriel ou téléphone exact) ne trouve que les comptes membres de `boutique` ou de `pos`. Un compte
  membre de `memoires` seulement, ou sans appartenance, n'apparaît pas, avec exactement la réponse « aucun compte » ; le rattacher
  par son identifiant (`POST /api/pos/customers/{id}/link`) répond 404 `USER_NOT_FOUND`, comme un compte inexistant. Avant la
  reprise : comme aujourd'hui ;
- au lot M3, un site ne pourra passer en mode « exiger » que si sa reprise est faite.

**Circuit de la reprise** (décision du propriétaire) : répétition sur une copie jetable de `db_demo`, avec un Postgres et un Redis
jetables sur un réseau à part, selon les règles de répétition de `docs/architecture.md` ; puis, sur chaque site, **la simulation
seule**, avec son rapport lu par le chef de projet ; application **site par site sur son accord, `demo` en premier**, photo avant
et après, tout au journal. Aucune application hors `demo` sans cet accord.

## 9. Ce que chaque front aura à faire (rien pour M1)

- Boutique web, application mobile de la boutique, Mémoires Vivantes web, Living Memory : envoyer `app` à la connexion, à la
  vérification du code, au rafraîchissement et à l'inscription ; afficher les conditions (`GET /api/apps/{app}/terms`) à
  l'inscription et au paiement en invité et envoyer leur `version` dans `termsVersion` ; au lot M3, traiter
  `APP_MEMBERSHIP_REQUIRED` par un écran « Rejoindre ».
- Caisse : rien (le rattachement d'une fiche crée l'appartenance ; sa connexion est une connexion d'équipe).
- Application de gestion, Boussole : rien.

## 10. Lots

| Lot | Contenu | État |
|---|---|---|
| M1 | Familles, table, appartenances créées aux entrées, conditions et version, routes de lecture, famille dans le jeton, mode « observer » | codé le 14/11/2026 |
| M2 | Reprise : commande `app:members:backfill` (simulation, rapport, `--apply`), état « reprise faite » du site, caisse limitée aux membres de la boutique ou de la caisse après la reprise | codé le 14/11/2026 ; application site par site sur accord |
| M3 | « Rejoindre » (refus, jeton de passage, `POST /api/apps/join`), acceptation à la première connexion d'un compte invité, quitter une famille, mode « exiger » par site | à faire |
| M4 | Listes d'administration et statistiques filtrées par appartenance | à faire |
