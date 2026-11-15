# Rapports PDF de la gestion (lot DOC)

Contrat accepté par le propriétaire le 14/11/2026. Un rapport est un PDF fabriqué par le serveur à partir de chiffres déjà calculés,
**sans aucun appel d'IA**. Lot DOC-1 (codé le 15/11/2026) : le moteur de mise en page et le rapport d'une **réponse de la console IA**.
Lot DOC-2 (à venir) : bilan de période et bilan de l'année, avec un avis de la console en option.

Code : `src/Gestion/Services/Report/`, `src/Gestion/Controller/GestionReportController.php`. Test :
`tests/Functional/Gestion/GestionReportsApiTest.php`. Console IA : `docs/console-gestion.md`.

## 1. Ce que fait le lot DOC-1

« Cette réponse en PDF » : une réponse de la console, **telle qu'elle a été lue**. Le serveur reprend les blocs gardés de la réponse
(`gestion_conversation_message.content.blocks`) : rien n'est recalculé, aucun modèle n'est appelé, aucune unité n'est décomptée. Si les
chiffres du site ont changé depuis, le rapport reste celui de la réponse. Lecture seule : le lot n'écrit que la liste de ses rapports.

## 2. Routes

Droits : ceux de toute route `/api/gestion` (`ROLE_ADMIN`, à jeton, module de gestion actif, sinon 403 `MODULE_INACTIVE`) ; le rapport
d'une réponse demande aussi le palier d'IA du site (403 `AI_NOT_INCLUDED`). Erreurs à la forme de la gestion : `{ error, code,
message, … }` ; une validation : 422 `VALIDATION_FAILED { fields }`.

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `POST /api/gestion/rapports` | `{ kind: "console.answer", messageId, locale? }` | 202, le rapport en préparation ; **200 si le fichier de la même réponse est déjà prêt** (`reused: true`) | 422 `fields.kind` `REQUIRED` \| `INVALID_VALUE` (`period` et `year` : lot DOC-2) ; `fields.messageId` `REQUIRED` \| `INVALID_ID` ; `fields.locale` `INVALID_VALUE` ; `fields.withAdvice` `NOT_ALLOWED` \| `MUST_BE_BOOLEAN` ; 404 `CONSOLE_MESSAGE_NOT_FOUND` ; 409 `CONSOLE_ANSWER_PENDING`, `CONSOLE_ANSWER_FAILED` ; 429 `REPORT_DAILY_LIMIT { limit, resetAt }` ; 403 `AI_NOT_INCLUDED` |
| `GET /api/gestion/rapports/{id}` | — | le rapport (à relire toutes les 3 secondes tant que `state` vaut `pending`) | 404 `REPORT_NOT_FOUND` |
| `GET /api/gestion/rapports/{id}/fichier` | — | `application/pdf`, en pièce jointe | 409 `REPORT_NOT_READY`, `REPORT_FAILED_NO_FILE` ; 404 `REPORT_NOT_FOUND` |
| `GET /api/gestion/rapports?page=&kind=` | — | `{ reports, page, pages, total }`, 20 par page, du plus récent au plus ancien | 422 `fields.kind` `INVALID_VALUE` |
| `DELETE /api/gestion/rapports/{id}` | — | 204 | 409 `REPORT_NOT_READY` ; 404 |

`messageId` est celui de la **réponse** (`messageId` rendu par la console), pas celui de la question. `locale` : `fr` ou `en`
(défaut : `?locale=`, sinon français) ; une autre langue est un autre rapport.

Un rapport :

```json
{ "id": 12, "kind": "console.answer", "state": "done", "title": "Que disent mes ventes ?", "locale": "fr",
  "params": { "messageId": 431 }, "createdAt": "2026-11-15T17:40:02+00:00", "readyAt": "2026-11-15T17:40:04+00:00",
  "expiresAt": "2027-02-13T17:40:02+00:00", "pages": 2, "size": 48211,
  "download": { "url": "/api/gestion/rapports/12/fichier", "filename": "rapport-que-disent-mes-ventes-12.pdf", "contentType": "application/pdf" },
  "advice": null, "error": null, "reused": false, "unitsCharged": 0, "credits": null }
```

- `state` : `pending`, `done`, `failed`. `download` n'est rempli que pour `done`. `pages` et `size` (octets) : connus une fois prêt.
- `error` (pour `failed`) : `{ code, message }`, code `REPORT_TOO_LONG` (plus de 40 pages ; `pages` dit combien), `REPORT_TIMEOUT`
  (fabrication trop longue, ou rapport resté en préparation), `REPORT_FAILED` ; le message est rédigé dans la langue de `?locale=`.
- `reused` : vrai quand la demande a rendu un rapport qui existait déjà (prêt, ou encore en préparation).
- `advice`, `credits` : toujours `null` dans ce lot ; `unitsCharged` : toujours 0. Ils serviront à l'avis de la console (DOC-2).
- Le fichier ne se lit jamais par un lien public : `download.url` est une route à jeton.

## 3. Garde-fous

| Règle | Valeur | Constante |
|---|---|---|
| Rapports par jour du magasin et par site | 30 (un rapport réemployé ne compte pas ; un rapport effacé compte encore : effacer ne rend pas une place) | `GestionReportService::DAILY_LIMIT` |
| Pages d'un rapport | 40 au plus, sinon `REPORT_TOO_LONG`, aucun fichier gardé | `MAX_PAGES` |
| Délai de fabrication | 120 secondes, mesuré à la fin du rendu ; un rapport resté `pending` 15 minutes passe à `failed` (`REPORT_TIMEOUT`) : la file `pdf` est partagée avec la mise en page des livres | `TIMEOUT_SECONDS`, `STALE_SECONDS` |
| Conservation | 90 jours (`expiresAt`) : passé ce délai le rapport ne se lit plus (404), puis sa ligne et son fichier sont retirés ; la réponse de la console, gardée 12 mois, permet de le refaire | `RETENTION_DAYS` |

Le retrait se fait à la lecture de la liste et au passage horaire de `app:gestion:notifications:envoyer`.

## 4. Fabrication

1. La demande crée la ligne `gestion_report` (`pending`) et confie le travail à la file `pdf` (`GenerateGestionReportMessage`,
   `GenerateGestionReportHandler`, jamais relancé : un échec est noté sur le rapport).
2. Le worker relit la réponse, la range en **sections déjà rédigées** (`ReportRenderer::document`), la met en page
   (`templates/gestion/report/document.html.twig`, Dompdf, A4 portrait), compte les pages, écrit le fichier dans le stockage privé du
   site (`var/storage/private_media/gestion-rapports/<code du site>/`).
3. La même réponse demandée de nouveau, dans la même langue, rend le fichier déjà prêt (empreinte de la demande : genre, réponse,
   langue, blocs, en-tête du site). Si le nom, l'adresse, les numéros ou le logo du site changent, c'est un nouveau rapport. Deux
   demandes au même instant ne donnent qu'une préparation (index unique). Un rapport effacé (`DELETE`) perd son fichier et ne se lit
   plus ; sa ligne reste jusqu'à la fin de sa conservation, pour le plafond du jour.

Mise en page : en-tête (nom du site, logo, adresse, SIRET, numéros TPS et TVQ s'ils sont saisis), titre (la question), « chiffres
calculés le … (heure du magasin) », la question, puis les blocs dans l'ordre, puis « d'où viennent ces chiffres » (les analyses de la
réponse, leur plage, la date du calcul) ; pied : nom du site, page x / y.

| Bloc de la console | Sur le papier |
|---|---|
| `text` | paragraphes |
| `figures` | cartes par rangées de trois : libellé, valeur, et les comparaisons gardées avec la carte (« Période précédente : … (+x %) », « An dernier : … ») |
| `table` | tableau, en-têtes répétés d'une page à l'autre, montants alignés à droite ; « et N autres lignes » (ou « et une autre ligne ») quand la console n'en a gardé qu'une partie (`more.count`) |
| `verdicts` | les groupes du bilan avec leur compte, puis une ligne par article : nom, verdict, pourquoi |
| `lesson` | la notion, sa définition, son illustration, les notions liées ; **pas** les questions de vérification (un jeu de l'écran) |
| `guide` | le titre de l'écran, à quoi il sert, ses points, la suite |
| type inconnu | laissé de côté |

Les valeurs sont écrites par `ReportValue` selon l'unité du bloc (`money` en cents de la devise du site, `percent`, `count`, `days`,
`ratio`, `date`, `text`), dans la langue demandée ; une valeur absente s'écrit par un tiret ; un texte reste un texte, même s'il
ressemble à un nombre (un article nommé « 2024 »). Tout texte d'un bloc est échappé.

## 5. Limites connues et suite

- Pas de graphique (la console n'a pas de bloc graphique) : lot suivant.
- Le délai de 120 secondes n'arrête pas un rendu en cours ; la borne réelle est la taille d'une réponse (50 lignes par tableau).
- Le logo est pris en PNG, JPEG ou GIF de 500 Ko au plus ; un autre format est laissé de côté sans message.
- Le numéro de TVA intracommunautaire n'est pas dans l'en-tête ; les tableaux du bilan et des sources n'ont pas d'en-tête de colonnes.
- Pas d'envoi par courriel : le téléchargement suffit (décision du propriétaire).
- DOC-2 : `kind: "period"` et `"year"`, `withAdvice` (un avis de la console, 1 unité, rendue si l'IA échoue), l'écran « rapports » au
  registre des écrans de la console.

## 6. Base

Migration `Version20270110100000`, script `scripts/migrate_all_v2_rapports.sh` : table `gestion_report` et trois index (dont l'index
unique d'une seule préparation par demande). Additive ; à
appliquer à toutes les bases **avant** l'échange du code. Un message de worker s'ajoute : les workers sont relancés à la bascule.
