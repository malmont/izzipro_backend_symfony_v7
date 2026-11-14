# Applications à membres — APPS-MEMBRES (contrat validé le 14/11/2026 ; lot M1 codé le 14/11/2026)

Un compte du site peut servir à plusieurs applications : la boutique, Mémoires Vivantes, la caisse. Jusqu'ici rien ne disait dans
laquelle un client était entré, ni quelles conditions il avait acceptées. Ce chantier le dit, **sans jamais ajouter un client à une
application à son insu** : une appartenance naît d'un geste du client (ou d'une invitation de l'équipe), et passer d'une famille
d'applications à une autre demandera une étape « Rejoindre » où il accepte les conditions de cette famille.

État : **M1 codé** (table, appartenances créées aux entrées, conditions et version, routes de lecture, famille dans le jeton, mode
« observer » : aucune connexion refusée). M2 (reprise de l'historique), M3 (« Rejoindre », mode « exiger », quitter une famille) et
M4 (listes filtrées) restent à faire. **M1 ne change rien pour un client ni pour un front qui n'a pas bougé.**

Code : `src/Security/AppFamilies.php`, `src/Services/AppMembershipService/`, `src/Entity/UserAppMembership.php`,
`src/Controller/AppMembershipController/`. Tests : `tests/Functional/Security/AppMembershipApiTest.php`, et le rattachement de
caisse dans `tests/Functional/Boutique/PosCustomerApiTest.php`.

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
- La liste est dans `AppFamilies` et nulle part ailleurs (`ALL`, `APPS`). Un front peut envoyer son code d'application ou la
  famille : le serveur rend toujours la **famille**.
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

Pas de nouvelle demande d'acceptation quand les conditions changent, dans ce chantier : la version acceptée est gardée, ce qui le
permettra plus tard.

## 5. Les parcours

| Parcours | Ce que fait le serveur | Lot |
|---|---|---|
| **Inscription dans une application** (`POST /api/register` avec `app`) | Le compte entre dans la famille (`register`). `termsVersion` (facultatif) est gardé **s'il est la version en vigueur** ; sinon l'appartenance est créée sans acceptation. Sans `app` : aucune appartenance. | M1 |
| **Compte client créé par l'équipe** (Mémoires Vivantes, `POST /api/memoires/admin/users`) | Membre de `memoires` (`invite`), **sans** conditions acceptées ; il les acceptera à sa première connexion (M3). Un compte d'équipe créé de même n'est pas membre. | M1 |
| **Commande en invité** (`POST /api/order/create-guest`) | La commande crée un compte **sans mot de passe choisi** (c'est le fonctionnement d'avant ce chantier) : **aucune appartenance**. `termsVersion` (facultatif) est gardé sur la commande s'il est la version en vigueur de la boutique. | M1 |
| **Ce client choisit son mot de passe** (« mot de passe oublié ») | C'est la création de son compte : il entre dans `boutique` (`guest_checkout`) **à la date et à la version de sa première commande en invité**. Un mot de passe posé par l'équipe n'est pas ce geste : aucune appartenance. | M1 |
| **Fiche de caisse rattachée à un compte** (consentement `account_link`) | Le compte entre dans `pos` (`pos_link`), à la version du texte consenti et à la date du consentement. La fiche effacée à la demande du client retire cette appartenance. | M1 |
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
- **Mode « observer »** (M1, le seul mode pour l'instant) : si le compte est un compte client qui n'est pas membre de cette
  famille, la session est rendue quand même et une ligne est écrite au journal de l'application :
  `[APPS-MEMBRES] observer : le compte N n'est pas membre de « famille » (login | otp-verify | refresh, site code) ; session rendue`.
  C'est ce que le mode « exiger » refusera. Compter ces lignes par site dit si un site est prêt.
- **Mode « exiger »** (M3) : par site, colonne `entreprise.app_membership_mode` (`observe` par défaut, déjà créée). On y passe un
  site seulement quand ses fronts sont prêts et sa reprise faite ; aucune session ouverte n'est coupée, le contrôle se fait à la
  connexion suivante.

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

**Reprise de l'existant (M2)** : une commande à part, `app:members:backfill <site>`, qui **simule par défaut** et rend un rapport ;
`--apply` écrit. Rejouable sans doublon, tout au journal. Une appartenance reprise porte la source `backfill`, la date de la plus
ancienne trace, et **aucune** version de conditions. Traces retenues :

- `memoires` : inscription par Living Memory, un livre dont le compte est le propriétaire, un paiement de chapitre, une invitation ;
- `boutique` : inscription par l'application mobile de la boutique, une commande en ligne du compte, une adresse, un avis, un
  abonnement, un panier de paiement ;
- `pos` : une fiche de caisse rattachée au compte avec le consentement de rattachement.

Les pièges, traités ainsi :

1. une vente en caisse porte le compte du **caissier** : jamais une trace d'appartenance ;
2. le compte d'un livre peut être celui du biographe : seul le propriétaire client compte ;
3. **un compte né d'une commande en invité sans mot de passe choisi ne reçoit aucune appartenance**, même avec des commandes ;
4. `signup_channel` vide ou `web_register` ne suffit jamais seul : il faut une trace d'usage ;
5. un compte d'équipe a des traces de travail : aucune appartenance ;
6. un compte sans trace reste sans appartenance : il passera par « Rejoindre » une fois son site en mode « exiger ».

**Circuit de la reprise** (décision du propriétaire) : répétition sur une copie jetable de `db_demo` selon les règles de
répétition de `docs/architecture.md` ; puis, sur chaque site, **la simulation seule**, avec un rapport chiffré lu par le chef de
projet ; application **site par site sur son accord, `demo` en premier**, photo avant et après, tout au journal. Aucune application
hors `demo` sans cet accord.

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
| M2 | Reprise : commande, simulation, rapport, application site par site | à faire |
| M3 | « Rejoindre » (refus, jeton de passage, `POST /api/apps/join`), acceptation à la première connexion d'un compte invité, quitter une famille, mode « exiger » par site | à faire |
| M4 | Listes d'administration et statistiques filtrées par appartenance | à faire |
