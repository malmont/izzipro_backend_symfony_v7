# Liens vers l'espace d'administration (lot LIEN, 14/11/2026)

Le serveur envoie des courriels (résumé de la prévention des pertes, demain l'Assistance et les rapports) qui doivent mener le gérant
au bon écran du web. Le serveur **ne connaît pas les chemins du web**. Il fabrique donc toujours la même adresse d'entrée, que le web
traduit lui-même vers son écran :

```
<adresse de l'espace d'administration>/admin/ouvrir?screen=<code>&<paramètres>
```

Exemple : `https://www.exemple.test/admin/ouvrir?screen=pertes.constats&isNew=1`.

Code : `App\Services\Site\AdminSpaceUrl` (le lien), `App\Services\Site\AdminSpaceLinks` (la liste de référence ci-dessous).
Tests : `tests/Functional/Admin/AdminSpaceUrlTest.php`.

## 1. L'adresse de l'espace d'administration

- Un réglage **par site** : « Adresse de l'espace d'administration », sur la fiche du site d'EasyAdmin (onglet Entreprise), colonne
  `entreprise.admin_space_url` (migration `Version20270104100000`, script `scripts/migrate_all_v2_lien.sh`).
- **Vide par défaut : le domaine principal du site sert alors**, en https (son domaine propre s'il en a un, sinon son adresse sur la
  plateforme). Un site n'a donc rien à régler tant que son espace d'administration est servi par son domaine.
- Forme acceptée : `https://hôte[:port][/chemin]`. Sans identifiants, sans paramètres, sans ancre ; la barre finale est retirée. Une
  valeur refusée est enregistrée vide (le domaine principal sert).
- Le registre des fonctionnalités le rend (`GET /api/site/features`, clé `site.admin_space_url`, type `url`) : `value` = le réglage,
  `effective` = l'adresse qui sert vraiment aux liens.

## 2. Le service

`AdminSpaceUrl::to(string $screen, array $params = []): ?string` rend le lien, ou **null** quand :
- le site n'a aucune adresse connue (ni réglage, ni domaine) ;
- le code d'écran n'est pas dans la liste de référence ;
- un paramètre obligatoire manque.

Un paramètre inconnu ou mal formé est **écarté** (le lien part sans lui). Un appelant qui reçoit null n'affiche pas de lien : le
courriel dit alors seulement où regarder.

## 3. Liste de référence, pour le web

C'est le contrat : un code ou un paramètre qui n'est pas ici ne part dans aucun lien du serveur. Le web doit savoir ouvrir chacun de
ces codes, et ignorer sans erreur un paramètre qu'il ne connaît pas encore.

Formes : **entier** (> 0), **drapeau** (`=1` quand il est présent, absent sinon), **une valeur** d'une liste fermée, **plusieurs
valeurs** d'une liste fermée séparées par des virgules.

| Code d'écran | Paramètres | Ce qu'il ouvre |
|---|---|---|
| `pertes.home` | aucun | Prévention des pertes, synthèse |
| `pertes.constats` | `isNew` (drapeau) ; `status` (plusieurs valeurs : `open`, `watch`, `explained`, `dismissed`, `confirmed`) ; `rule` (une valeur : `CASH_DISCREPANCY`, `DRAWER_NO_SALE`, `VOID_AFTER_SALE`, `REFUND_PATTERN`, `DISCOUNT_PATTERN`, `PRICE_OVERRIDE`, `APPROVAL_PATTERN`) | liste des constats, filtrée |
| `pertes.constat` | `id` (entier, **obligatoire** : l'identifiant du constat, pas son numéro) | fiche d'un constat |
| `pertes.regles` | aucun | règles et seuils |
| `pertes.reglages` | aucun | réglages du module |
| `pertes.exercices` | aucun | mode exercice (lot EX-1) |
| `pertes.pieces` | aucun | pièces et puces (lot L2-a) |
| `pertes.installation` | aucun | assistant d'installation des étiquettes à puce et gamme de matériel (lot L2-b) |
| `assistance` | `status` (une valeur : `new`, `open`, `waiting_customer`, `closed`) ; `overdue` (drapeau) ; `awaiting` (drapeau) | boîte des demandes |
| `assistance.demande` | `id` (entier, **obligatoire**) | fiche d'une demande |
| `assistance-reglages` | aucun | réglages de l'Assistance |
| `rapports` | `id` (entier, facultatif) | « Mes rapports » (lot DOC), ouverte sur un rapport quand `id` est donné |

**Codes de l'Assistance.** `assistance` et `assistance-reglages` sont déjà émis par le serveur (« Ma journée », cheminement) : ils
gardent leur forme, sans point, pour ne rien casser. Une demande seule prend la forme à point, `assistance.demande`.

**Qui s'en sert aujourd'hui.** Le courriel de résumé de la prévention des pertes : `pertes.constats` avec `isNew=1`. Le courriel
d'exercice (lot EX-1) : `pertes.exercices`. Les autres codes sont prêts pour l'Assistance et les rapports.

## 4. Une seule exception : le choix du mot de passe d'un compte invité

`<adresse de l'espace d'administration>/admin/activer#token=<jeton>` (lot SUPPORT-WEB, 14/11/2026 : invitation d'un agent
d'assistance). Ce n'est **pas** un code d'écran de la liste, et le lien ne passe **pas** par `/admin/ouvrir` : le jeton à usage unique
(64 caractères hexadécimaux, 48 heures) voyage dans le **fragment** de l'adresse, jamais dans ses paramètres. Un fragment n'est envoyé
à aucun serveur : il n'entre ni dans le journal du web, ni dans celui d'un proxy, ni dans l'en-tête Referer. Le web lit le fragment,
le retire de l'adresse, puis envoie le jeton dans le corps d'un POST (`/api/password-reset/verify`, `/api/password-reset/confirm` ;
`docs/assistance.md`). Code : `AdminSpaceUrl::activation()`, `AdminSpaceLinks::ACTIVATION_PATH`.

Le même écran sert au **mot de passe oublié d'un compte de l'espace d'administration** : quand la demande porte `"platform":
"webadmin"` (`POST /api/password-reset/request`) et que le compte a accès à cet espace (administrateur, agent d'assistance,
propriétaire de la plateforme), le courriel porte ce lien, jeton dans le fragment, valable 1 heure (`kind: "reset"` à la vérification).
Sans ce champ, ou pour un compte client, le lien reste celui du site (`/reset-password?token=…`).

**À faire, noté pour la campagne de tests** : le lien de mot de passe oublié de la **boutique** porte encore son jeton dans les
paramètres de l'adresse, donc dans les journaux. Il faudra le passer au fragment aussi, avec le site Next et les applications mobiles
qui l'ouvrent.

## 5. Ajouter un écran à la liste

Ajouter le code à `AdminSpaceLinks::LINKS` avec ses paramètres et leur forme, l'écrire dans le tableau ci-dessus, et prévenir le web
**avant** qu'un courriel ne l'envoie. Si le code existe aussi dans le registre des écrans de la console (`ConsoleScreens`), ses
paramètres communs gardent la même forme (le test le vérifie).
