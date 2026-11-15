# Rapports PDF de la gestion (lot DOC)

Contrat accepté par le propriétaire le 14/11/2026. Un rapport est un PDF fabriqué par le serveur à partir de chiffres déjà calculés,
**sans aucun appel d'IA**. Lot DOC-1 (codé le 15/11/2026) : le moteur de mise en page et le rapport d'une **réponse de la console IA**.
Lot DOC-2 (codé le 15/11/2026) : **bilan de période** et **bilan de l'année**, avec un avis de la console en option (§ 7).

Code : `src/Gestion/Services/Report/`, `src/Gestion/Controller/GestionReportController.php`. Tests :
`tests/Functional/Gestion/GestionReportsApiTest.php` (DOC-1), `GestionReviewReportsApiTest.php` (DOC-2). Console IA :
`docs/console-gestion.md`.

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
| `POST /api/gestion/rapports` | `{ kind: "console.answer", messageId, locale? }` ; bilans (§ 7) : `{ kind: "period", periodId, withAdvice?, locale? }`, `{ kind: "year", year?, withAdvice?, locale? }` | 202, le rapport en préparation ; **200 si le fichier de la même demande est déjà prêt** (`reused: true`) | 422 `fields.kind` `REQUIRED` \| `INVALID_VALUE` ; `fields.messageId`, `fields.periodId` `REQUIRED` \| `INVALID_ID` ; `fields.year` `OUT_OF_RANGE` ; `fields.locale` `INVALID_VALUE` ; `fields.withAdvice` `MUST_BE_BOOLEAN` \| `NOT_ALLOWED` (`true` sur une réponse de la console ; `false` y est admis) ; 404 `CONSOLE_MESSAGE_NOT_FOUND`, `PERIOD_NOT_FOUND` ; 409 `CONSOLE_ANSWER_PENDING`, `CONSOLE_ANSWER_FAILED` ; 429 `REPORT_DAILY_LIMIT { limit, resetAt }` ; 403 `AI_NOT_INCLUDED` (réponse de la console, ou bilan avec avis) ; avec avis : 402 `AI_CREDITS_EXHAUSTED`, 429 `AI_DAILY_LIMIT`, 429 `AI_TOO_MANY_FAILURES` (trop d'échecs de l'IA sur 24 heures) |
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
- `advice`, `credits`, `unitsCharged` : l'avis de la console d'un bilan (§ 7) ; `null`, `null` et 0 pour une réponse de la console.
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
| `figures` | cartes par rangées de trois : libellé, valeur, et les comparaisons gardées avec la carte (« Période précédente : … (+x %) », « An dernier : … »). Un bloc de cartes est d'un seul tenant : s'il ne tient pas au bas de la page, il passe entier à la suivante (jamais une rangée seule) ; la variation entre parenthèses ne se coupe pas, et le « % » est tenu par une espace insécable (retouches du 15/11/2026) |
| `table` | tableau, en-têtes répétés d'une page à l'autre, montants alignés à droite ; « et N autres lignes » (ou « et une autre ligne ») quand la console n'en a gardé qu'une partie (`more.count`) |
| `verdicts` | les groupes du bilan avec leur compte, puis une ligne par article : nom, verdict, pourquoi |
| `lesson` | la notion, sa définition, son illustration, les notions liées ; **pas** les questions de vérification (un jeu de l'écran) |
| `guide` | le titre de l'écran, à quoi il sert, ses points, la suite |
| type inconnu | laissé de côté |

Les valeurs sont écrites par `ReportValue` selon l'unité du bloc (`money` en cents de la devise du site, `percent`, `count`, `days`,
`ratio`, `date`, `text`), dans la langue demandée ; un pourcentage est arrondi à une décimale, comme à l'écran et dans la console
(« 12,02 » s'écrit « 12 % ») ; une valeur absente s'écrit par un tiret ; un texte reste un texte, même s'il
ressemble à un nombre (un article nommé « 2024 »). Tout texte d'un bloc est échappé.

## 5. Limites connues et suite

- Pas de graphique (la console n'a pas de bloc graphique) : lot suivant.
- Le délai de 120 secondes n'arrête pas un rendu en cours ; la borne réelle est la taille d'une réponse (50 lignes par tableau).
- Le logo est pris en PNG, JPEG ou GIF de 500 Ko au plus ; un autre format est laissé de côté sans message.
- Le numéro de TVA intracommunautaire n'est pas dans l'en-tête ; les tableaux du bilan et des sources n'ont pas d'en-tête de colonnes.
- Pas d'envoi par courriel : le téléchargement suffit (décision du propriétaire).
- Bilans : pas de commentaire rédigé sans `withAdvice` ; le stock d'un bilan de période est celui d'aujourd'hui, donc absent d'une
  période passée ; le bilan de l'année n'a pas les leviers chiffrés de l'écran Année.

## 6. Base

Migration `Version20270110100000`, script `scripts/migrate_all_v2_rapports.sh` : table `gestion_report` et trois index (dont l'index
unique d'une seule préparation par demande). Additive ; à
appliquer à toutes les bases **avant** l'échange du code. Un message de worker s'ajoute : les workers sont relancés à la bascule.

Lot DOC-2 : migration `Version20270111100000`, script `scripts/migrate_all_v2_rapports_doc2.sh` : colonnes `content` (les chiffres
calculés à la demande) et `advice` (l'avis de la console : sa question, puis son issue), en JSONB, nulles pour une réponse de la
console. Additive, à appliquer avant l'échange du code.

## 7. Bilans de période et d'année (lot DOC-2)

**Ce que c'est.** Pour une période (`kind: "period"`, `periodId`) ou pour une année (`kind: "year"`, `year` facultatif : sans lui,
l'exercice en cours, celui de l'écran Année), le serveur lance lui-même les **analyses nommées de la console** (`AnalysisCatalog`,
`docs/console-gestion.md`) et met leurs chiffres en page (`ReportReview`). Un chiffre du bilan est donc celui de l'écran, au cent.
Sans `withAdvice` : aucun modèle n'est appelé, 0 unité, et le palier d'IA n'est pas exigé.

| Bilan | Analyses, dans l'ordre du papier |
|---|---|
| Période | `period.result` (résultat), `sales.overview` (ventes et marge, avec la période précédente et l'an dernier), `budget.status` (prévu et réel, tableau des postes) ; `stock.overview` (valeur du stock, articles en stock bas) **seulement pour la période en cours** : ce stock est celui d'aujourd'hui |
| Année | `year.overview` : chiffres clés, puis le **mois par mois** en tableau, dans l'ordre de l'exercice (le rang de chaque mois reste en colonne) |

Chaque analyse fait une partie : son titre, sa plage, ses cartes, son tableau ; une partie sans donnée le dit. La mention sous le
titre : « Chiffres provisoires au … » tant que la période n'est pas clôturée (ou que l'exercice n'est pas terminé) ; « Période
clôturée le … : ses chiffres sont figés » ensuite. Le document se termine par « d'où viennent ces chiffres ».

**Quand les chiffres sont calculés.** À la demande, dans la requête : leur résultat est gardé avec le rapport (`gestion_report.content`)
et le worker met en page **ces chiffres-là**, sans rien recalculer. C'est aussi ce qui fait l'empreinte : la même période, dans la
même langue, **sans changement de ses chiffres ni de sa mention**, rend le fichier déjà prêt (200, `reused: true`) ; une vente de
plus, ou le jour qui change sur une période provisoire, fait un nouveau bilan.

`params` d'un bilan : `{ periodId, withAdvice }` ou `{ year, withAdvice }` (`year` : l'année retenue, même quand elle n'était pas
donnée). `year` qui n'est pas un entier, avant 2000, ou au-delà de l'exercice en cours (une année que l'écran Année refuse) : 422
`OUT_OF_RANGE`. Une collection qui n'est pas une période, ou supprimée : 404 `PERIOD_NOT_FOUND`. La date d'une clôture faite à la
main est écrite au jour du magasin.

**L'avis de la console (`withAdvice: true`).** Le bilan se termine par une partie « Avis de la console », rédigée par l'IA.

1. À la demande, après les contrôles et le réemploi : la question (« Donne ton avis sur le bilan de cette période… ») est posée à la
   console **au nom du gérant** (`ConsoleService::ask`, sans mise en file). C'est elle qui refuse, avant toute création : 403
   `AI_NOT_INCLUDED` (dit dès le début de la demande), 402 `AI_CREDITS_EXHAUSTED`, 429 `AI_DAILY_LIMIT`. **1 unité est réservée.**
2. Le worker `pdf` fait rédiger la réponse (`ConsoleResponder::answer($messageId, $forced)`) sur **les analyses du bilan, imposées
   par le serveur** : l'appel de choix n'a pas lieu, le modèle ne fait que rédiger (un seul appel), aux règles de la console (aucun
   nombre que le serveur n'a pas fourni). Les analyses sont relancées à cet instant, quelques secondes après la demande. La question
   demande des phrases, sans liste numérotée : un « 1. » serait un nombre inconnu pour le contrôle de la console.
3. L'avis est « rédigé » si la console a rendu un texte du modèle sur ces analyses : l'unité est décomptée (`unitsCharged: 1`, relu
   en base, `advice: { included: true }`). Sinon : le bilan sort **sans** avis, avec une ligne qui le dit, l'unité est rendue,
   `advice: { included: false, code }`. `AI_UNAVAILABLE` : IA en panne, ou réponse de repli écrite par le serveur.
   `CONSOLE_TIMEOUT` : la question a attendu plus de 120 secondes avant que le worker la prenne (file `pdf` occupée) ; le worker la
   clôt lui-même (`ConsoleService::releaseStale`), sans appeler le modèle. Ce rapport prend alors une **empreinte à lui** : redemander
   l'avis le redemande vraiment, et demander le bilan sans avis fait un bilan propre (aucune demande ne retombe sur ce fichier).
4. L'échange se relit dans l'historique de la console (il compte dans son plafond du jour). `advice` vaut `null` tant que le
   rapport n'est pas prêt ; `credits` est le solde du site `{ monthly, used, remaining, resetAt }` pour une demande avec avis, `null`
   sinon. Le délai de fabrication de 120 secondes se mesure après la rédaction de l'avis (elle est bornée par la console). Si le
   fichier échoue après un avis rédigé (plus de 40 pages), l'unité reste notée sur le rapport et l'avis reste lisible dans la
   console. Si la création du rapport échoue après la question posée, la question est retirée de l'historique et son unité rendue.

La même demande avec avis, sans changement des chiffres : le fichier déjà prêt, aucune nouvelle question, aucune nouvelle unité.

**Écran.** `rapports` (la liste « Mes rapports ») est au registre des écrans de la console (`ConsoleScreens`), avec son guide en
français et en anglais (`translations/ecrans.*.yaml`).
