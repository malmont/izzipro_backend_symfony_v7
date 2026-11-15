# Prévention des pertes : constats tirés de la caisse, preuves datées, décision du gérant

Fiche du module « Prévention des pertes » (vol en boutique physique), section de l'application de gestion activable avec sa propre clé.
Décisions du propriétaire du 14/11/2026 (15 décisions, § 12). **Lot L1-a codé le 14/11/2026** : caisse seule, sans matériel, vague 1 des
règles. **Lot L1-b codé le 14/11/2026** : vague 2 des règles, aperçu d'un seuil avant de l'appliquer, codes d'écran et guides, vignette de
l'accueil, étapes du cheminement, courriel de résumé du jour. **Lot EX-1 codé le 14/11/2026** : mode exercice (constats fictifs que le
gérant lance et efface, ouverts sans le droit du module et avant la notice ; § 8). Code : `src/LossPrevention/` (module autonome, mêmes couches que le reste). Web : session « securite de la boutique » ; mobile :
le chef de projet, sur les mêmes routes. Caisse : `docs/caisse.md`. Bloc cashDesk des statistiques : `docs/statistiques-gestion.md` § 6.

## 1. Principes

- **Le module n'écrit que dans ses propres tables** (`loss_*`). Il lit la caisse, le stock et les commandes ; il ne modifie ni une vente, ni un
  stock, ni une session, ni le journal de caisse. Test : après une analyse, le journal de caisse a le même nombre d'entrées et sa chaîne est
  intacte.
- **Vocabulaire** : fait (chose observée) → règle → **constat** → dossier de preuves → avis → décision du gérant. « Constat », pas
  « incident » (pris par `docs/incidents-et-signalements.md`).
- **Aucune suite automatique** : la décision reste humaine. Jamais, nulle part : reconnaissance des visages, son, caméra en cabine, analyse
  de gestes par vidéo (liste `never` des réglages, non modifiable).
- **Le serveur est seul juge** : règles, seuils, montants (cents entiers), textes français et anglais (`translations/pertes.{fr,en}.yaml`,
  `?locale=`), journées au fuseau du magasin (`StoreClock`, comme les statistiques par caissier et le bloc cashDesk).
- **Pas de classement ni de note par employé.** Une personne n'est le sujet d'un constat que pour une règle « par personne » (§ 4), qui
  s'éteint par pays et par site. **Le résumé d'un constat ne nomme jamais personne** : la liste et la synthèse sont sans nom. Les noms (jamais
  les courriels) ne sortent que dans la fiche d'un constat : « qui était en poste », les lignes des preuves, le sujet d'une règle par personne.
- **Un constat ne naît jamais dans la transaction d'une vente ou d'une fermeture** : aucun code de caisse n'appelle le module.

## 2. Clés, garde et accès

- **Droit d'usage `module.loss_prevention`** (`FeatureCatalog`), gouverné comme les autres (essai, gratuité, abonnement, suspension),
  projeté sur `entreprise.is_loss_prevention_active` ; visible dans le registre du site (`GET /api/site/features`). **Il dépend de la gestion**
  (`app.management`) : les routes sont sous `/api/gestion/pertes`.
- **Deux gardes en mode « live » de la plateforme** (en mode « demo », rien n'est refusé) : `ModuleAccessSubscriber` (priorité 15) exige la
  gestion ; `LossPrevention\LossPreventionModuleSubscriber` (priorité 14, avant le pare-feu) exige le module. Refus : 403
  `{ error: "MODULE_INACTIVE", code, module: "loss_prevention", message, feature: "module.loss_prevention", requestable, request, trial }`.
  **`feature` porte toujours la clé refusée** : `app.management` si c'est la gestion qui est éteinte, `module.loss_prevention` si c'est le
  module (les écrans ne ferment pas toute la gestion pour un refus du module).
- **Accès : `ROLE_ADMIN` seulement** (règle `access_control` de `/api/gestion`). Responsable de caisse, caissier, client, anonyme : refusés.
- **Clé de boîtier** (lot L3, réservée, non codée) : le boîtier de la boutique s'activera comme une tablette de caisse (clé d'activation,
  jeton signé envoyé à chaque lot de faits) ; une clé par boîtier, révocable, sans aucun droit de lecture.
- **Offre** (lot L6) : deux offres de la plateforme (« sans matériel », « avec matériel ») accordent la même clé ; le matériel est une vente à
  part ; catalogue de matériel servi par le serveur, à plusieurs niveaux pour un même besoin.

## 3. Analyse (`Services/LossAnalysisService`)

- **Source** : le journal de caisse (`pos_audit_log`), inaltérable et chaîné. Sa séquence est continue et écrite sous verrou dans la
  transaction de chaque événement : les entrées sont validées dans l'ordre. **Curseur** `loss_setting.cursor_sequence` : chaque entrée est
  présentée une seule fois aux règles, y compris une vente hors ligne synchronisée plus tard (son entrée prend une séquence à la
  synchronisation ; la vente est datée par `order.order_date`, son heure réelle).
- **Du journal, on ne prend que** : type, séquence, heure, utilisateur, poste, session, et le sujet et les données décodés du JSON
  (`LossAuditEntry` ; clés « présentes ou absentes », jamais obligatoires). **Les montants sont relus dans les tables sources** (rapport de
  clôture, vente, paiements). **Le contenu du journal n'est jamais recopié** : il porte le courriel du caissier.
- **Un passage** est borné (`BATCH` = 2 000 entrées), tient dans une transaction (constats, preuves et curseur avancent ensemble), et prend
  un verrou consultatif par site : un second lancement rend `busy` sans attendre. Lot plein : le curseur s'arrête à la dernière entrée lue.
- **Une règle en échec n'est jamais silencieuse.** L'entrée est sautée pour cette règle seulement (un point de reprise SQL par règle : ce
  qu'une autre règle a écrit pour la même entrée est gardé), et elle ne lui sera plus présentée. `skipped` est compté, gardé au journal du
  module (`ENTRIES_SKIPPED` : nombre d'opérations, chacune une fois, et séquences), rendu par la synthèse (`lastAnalysis.skipped { total,
  lastAt }`, remarque `ENTRIES_SKIPPED`) et par la commande, **qui sort en échec**. La remarque de la synthèse ne porte que sur les 30
  derniers jours : elle s'éteint d'elle-même (le journal garde tout). Le détail va au journal du serveur.
- **Lectures bornées du journal de caisse** : il ne se purge jamais et n'a d'index que sur la séquence, le type et (poste, heure). Les
  lectures par session passent par ce dernier (`LossAuditEntry::ofSession` : poste et heure d'ouverture de la session). Aucun index n'est
  ajouté à cette table de caisse.
- **Quand** : (1) commande `app:pertes:analyser` (`--tenant=`, `--passes=`, `--courriel-maintenant` : le courriel de résumé sans attendre 8 h, pour un lancement à la main ; `--sans-courriel` : l'analyse seule, aucun courriel par ce lancement ; la commande dit, par site, si le courriel de résumé est parti (« envoyé à N destinataire(s) ») ou s'il est retenu par le niveau de notification des destinataires (« retenu », rien n'est parti : `LossAnalyseCommand::digestLine`)), lancée par le minuteur de l'hôte
  `ops/systemd/pertes-analyse.{service,timer}` (minutes 8, 23, 38 et 53 ; installation en tête du fichier `.service`) : **seulement sur les
  sites dont le module est accordé** (`is_loss_prevention_active`), même en mode « demo » de la plateforme ; (2) à la lecture de la synthèse
  si la dernière analyse a plus de 10 minutes (sauf `analyse=0`) ; (3) `POST /api/gestion/pertes/analyse`.
- **Première analyse** : reprise des 30 derniers jours ; les constats nés de ces entrées sont marqués `backfill` (« reprise ») et ne comptent
  pas comme nouveaux.
- **Aucune analyse** (`blocked`, le curseur ne bouge pas) dans deux cas : `MODULE_NOT_GRANTED`, le droit du module n'est pas accordé au
  site, **même en mode « demo » de la plateforme** (les écrans restent visibles, avec la mention ; rien n'est analysé sur un site qui n'a pas
  le module) ; `STAFF_NOTICE_REQUIRED`, la notice aux salariés exigée par le pays n'est pas confirmée.
- **Là où la notice est exigée, rien de ce qui précède sa confirmation n'est analysé** : à la confirmation, le plancher
  `loss_setting.analysis_floor_sequence` et le curseur sont posés sur la dernière entrée du journal de caisse. Pas de reprise des 30 jours ;
  une période sans notice (notice retirée puis reconfirmée) n'est jamais relue ; une règle qui relit une session ne remonte pas sous le
  plancher. La reprise de 30 jours ne vaut que là où la notice est un simple rappel.
- **Personne n'est nommé pour un acte d'avant la notice** : un retour ou une annulation d'après la notice relit une vente d'origine qui peut
  la précéder ; son vendeur n'est alors ni mis « en poste », ni gardé dans la copie de la preuve (`LossAnalysisContext::beforeFloor`, utilisé
  par `RefundPatternRule` et `VoidAfterSaleRule`). La date du plancher est celle de sa dernière entrée du journal de caisse, à la seconde :
  une vente faite dans la même seconde est tenue pour antérieure (côté prudent).
- **Analyse arrêtée = rien d'annoncé** : quand `blocked` n'est pas nul (droit retiré, notice non confirmée ou retirée), ni la vignette de
  l'accueil ni le courriel de résumé ne parlent des constats déjà ouverts ; les écrans de la section, eux, restent lisibles.
- **Limite** : le serveur ne voit que les ventes enregistrées. Une ligne retirée du panier avant paiement et un panier abandonné ne laissent
  aucune trace (le panier vit dans la tablette). Deux faits réservés pour la reprise de la caisse : `CART_LINE_REMOVED`, `SALE_ABANDONED`.

## 4. Règles (`Services/LossRuleCatalog`, `Services/Rule/`)

Chaque règle s'éteint ; chaque paramètre a ses bornes ; **changer un seuil ne ferme ni ne supprime aucun constat : il ne vaut que pour les
analyses suivantes**. Valeurs par défaut validées par le propriétaire.

| Code | Vague | Ouvre un constat quand | Paramètres (défaut, bornes) | Sujet et clé |
|---|---|---|---|---|
| `CASH_DISCREPANCY` | 1 | une clôture a un écart (compté − attendu) qui dépasse, en valeur absolue, le plus grand de l'écart toléré du site (`pos_discrepancy_threshold`, 0 par défaut) et de `minAmount`. Clôture sans comptage : rien | `minAmount` 500 cents (0 à 1 000 000) | rapport de clôture ; `CASH_DISCREPANCY:z_report:<id>` |
| `CASH_DISCREPANCY`, par personne (`CashShortageSeriesRule`, règle à part) | 1 | `count` manques (écart négatif au-delà du seuil) fermés par le même compte en `days` jours | `count` 3 (2 à 20), `days` 30 (7 à 90) | `user_window` (le compte) ; un constat par fenêtre, complété tant qu'elle court ; la recherche du constat à compléter est bornée à la fenêtre de la clôture lue (un rejeu d'une clôture ancienne ne touche jamais un constat né plus tard) |
| `DRAWER_NO_SALE` | 1 | plus de `maxPerSession` ouvertures du tiroir sans vente dans une session | `maxPerSession` 3 (0 à 50) | session ; `DRAWER_NO_SALE:session:<id>` |
| `VOID_AFTER_SALE`, rapide | 1 | une vente qui avait une part en espèces est annulée dans les `minutes` | `minutes` 10 (1 à 240) | vente ; `VOID_AFTER_SALE:order:<id>` |
| `VOID_AFTER_SALE`, série | 1 | `perSession` annulations dans une même session | `perSession` 3 (2 à 50) | session ; `VOID_AFTER_SALE:session:<id>` |
| `REFUND_PATTERN` | 2 | un retour sans ticket ; ou des espèces rendues pour une vente d'origine sans aucune part en espèces (les deux sont interdits par défaut dans la caisse : ils n'arrivent que si le site les a autorisés) | aucun | retour (`pos_return`) ; `REFUND_PATTERN:return:<id>` |
| `REFUND_PATTERN`, par personne (`RefundSeriesRule`) | 2 | `count` retours faits par le même compte dans une journée du magasin | `count` 3 (2 à 50) | `user_window` ; `REFUND_PATTERN:person:<compte>:day:<jour>` |
| `DISCOUNT_PATTERN` | 2 | `perSession` ventes remisées au motif « Autre » (`autre`, `other`), ou sans motif (ni code, ni motif écrit : un motif écrit sans code est un motif et ne compte pas), dans une session. **Limite** : une vente remisée synchronisée après une vente hors ligne n'écrit pas `DISCOUNT_APPLIED` au journal de caisse (la synchronisation n'écrit que `SALE` et `PRICE_CHANGED`) : la règle ne la voit pas ; à demander à la caisse quand elle reprendra | `perSession` 3 (2 à 50) | session ; `DISCOUNT_PATTERN:session:<id>` |
| `DISCOUNT_PATTERN`, par personne (`DiscountSeriesRule`) | 2 | `count` ventes en `days` jours, encaissées par le même compte, dont la remise atteint `share` % du plafond du caissier (en pourcentage du brut ou en montant ; un plafond réglé à 0 n'est pas un plafond : son critère est ignoré) ; ventes relues dans les commandes de caisse | `count` 5 (2 à 50), `days` 7 (1 à 30), `share` 90 % (50 à 100) | `user_window` ; un constat par fenêtre |
| `PRICE_OVERRIDE` | 2 | `perSession` baisses de prix à la main dans une session (une hausse ne compte pas) ; montant en jeu : la somme des baisses | `perSession` 3 (2 à 50) | session ; `PRICE_OVERRIDE:session:<id>` |
| `APPROVAL_PATTERN` | 2 | `perDay` validations d'un responsable refusées (code faux, code bloqué, compte sans le droit de valider, trop d'essais) **sur une même tablette dans une journée du magasin**. Une validation refusée est journalisée sans session ni poste : seule la tablette y est connue. Une demande mal formée et un responsable sans code ne comptent pas | `perDay` 3 (2 à 50) | tablette (`terminal`) ; `APPROVAL_PATTERN:terminal:<id>:day:<jour>` |

- Date d'une annulation : `order.canceled_at` (posé par `CancelOrderUseCase` pour tous les chemins), sinon l'heure de l'entrée REFUND pour
  une annulation d'avant PAYS-1b ; un retour (commande de type 2) n'est pas une annulation.
- Réservées (les données existent déjà au journal) : `CLOSE_WITHOUT_COUNT`, `REPRINT_PATTERN`, `GIFT_CARD_PATTERN`, `CUSTOM_ITEM_PATTERN`,
  `OFFLINE_FORCED`, paiements par carte commencés puis annulés, `OFF_HOURS` (aucun réglage d'heures d'ouverture du magasin n'existe).
- Garde-fou petite équipe (pour les règles en proportion de la vague 2) : au moins 20 ventes sur la période, sinon la règle compte en nombres.

## 5. Constat (`loss_finding`)

`{ id, number, rule { code, label }, personPattern, status, statusLabel, summary, storeDay, cashRegister { id, name } | null, amountAtStake,
currency, isNew, newEvidenceSinceDecision, backfill, updatedAt }` (ligne de la liste) ; la fiche ajoute `from`, `to`, `sessionId`,
`subject { type: z_report | session | order | return | terminal | user_window, id, name?, label? }`, `onDuty [{ id, name, role: seller | closer | approver |
operator, roleLabel }]`, `onDutyNotice`, `amountNotice`, `evidence []`, `evidenceIntact`, `opinion: null` (lot L5),
`decision { status, note, at, by { id, name } } | null`, `history [{ at, previous, status, statusLabel, hasNote, by }]`, `lastEvidenceAt`,
`createdAt`, `screen`.

- **États** : `open` (nouveau), `watch` (à suivre), `explained` (expliqué : erreur ou raison légitime), `dismissed` (classé sans suite),
  `confirmed` (confirmé). Note obligatoire pour `explained` et `confirmed` ; revenir à `open` est permis ; chaque changement est gardé
  (`history`, sans le texte de la note). Décider, c'est avoir vu : un constat décidé n'est plus « nouveau ». Rien ne se supprime.
- **Sans doublon** : une clé par constat (règle + sujet + fenêtre). Rejouer l'analyse complète un constat ; elle ne rouvre jamais un constat
  décidé : `newEvidenceSinceDecision` dit qu'il y a du nouveau depuis la décision.
- `isNew` : jamais consulté par un administrateur (et pas une reprise). Un constat né de la reprise cesse d'en être une dès qu'une preuve
  postérieure à la première analyse s'y ajoute : il redevient « nouveau ». `amountAtStake` : montant concerné, pas une perte prouvée.
- En base, `facts` ne garde que des nombres et `on_duty` que des identifiants et des rôles : résumé et noms sont écrits à la lecture.

## 6. Preuves (`loss_evidence`) et journal du module (`loss_journal`)

Preuve : `{ id, kind: z_report | drawer | order | credit_note | return | discount | price_change | approval, at, atLabel, title, lines [{ label, value }], ref { type, id, sequence, hash },
screen, fingerprint }`.

- **Copie figée ET renvoi** : la copie (`snapshot`, JSON canonique : identifiants, montants, dates, numéros, et les **codes de motif**
  choisis à la caisse, qui sont des libellés du site et non un texte du caissier ; jamais un nom de personne, un courriel, un texte libre
  ni le nom d'un article) garde l'écran stable ; le renvoi (pour une entrée du journal de caisse : sa séquence et son empreinte) permet de vérifier.
  `fingerprint` = SHA-256 de la copie ; `evidenceIntact` la recalcule à la lecture.
- **Relu dans la caisse à l'affichage, jamais copié** : le nom de l'article d'un prix baissé à la main et les motifs écrits d'une remise,
  lus dans l'entrée du journal de caisse par le renvoi (`LossFindingReader::liveFromJournal`) ; la preuve d'une remise garde seulement
  `writtenReasons`, le nombre de remises au motif écrit. Une preuve écrite avant ce changement garde son ancien champ `article`.
- **Preuves sans renvoi au journal de caisse** : dans un constat par personne sur des retours (`RefundSeriesRule`), seul le retour qui
  déclenche le constat porte la séquence et l'empreinte de son entrée ; les retours antérieurs de la journée sont relus dans `pos_return`
  et renvoient à leur ligne (`ref { type: pos_return, id }`), sans séquence. Même chose pour les ventes d'une série de remises
  (`DiscountSeriesRule`, renvoi à la commande).
- **Aucun texte libre dans une table inaltérable.** Le motif d'écart et le motif d'ouverture du tiroir saisis par le caissier ne sont pas
  recopiés dans la preuve : la fiche les lit dans la caisse, par le renvoi, au moment de l'affichage (la caisse reste le seul endroit qui
  les garde). La note de décision ne vit que sur le constat, dans sa dernière version, où elle se corrige ; l'historique et le journal du
  module gardent qui, quand, l'état d'avant, l'état d'après et « avec une note » oui ou non.
- **`lines`** : rédigées à la lecture, dans la langue demandée ; l'écran n'interprète aucune donnée brute.
- **`screen`** : code d'écran de la gestion quand il existe ; `null` en L1 (aucun écran pour une vente au comptoir, une session, une clôture).
- **Inaltérables** : un déclencheur de la base refuse modification, suppression et vidage de `loss_evidence` et `loss_journal`
  (`loss_records_immutable()`). La porte de purge prévue en L1-a (`loss.purge = 'on'`), dont rien ne se servait, **n'existe plus** depuis le
  lot EX-1 (migration `Version20270105100000`) : la seule porte est `loss.purge = 'exercise'`, et elle ne laisse supprimer que ce qui
  appartient à un constat d'exercice (voir « Mode exercice », § 8). Rien d'un vrai constat ne se supprime : **aucune purge n'existe**
  (décision : aucune purge automatique sans l'accord du propriétaire) ; le futur lot de conservation ouvrira sa propre porte, avec ses
  conditions.
- **Journal du module** (à part du journal de caisse) : `FINDING_OPENED`, `FINDING_COMPLETED`, `FINDING_VIEWED` (la fiche : une fois par
  administrateur et par journée du magasin ; la liste, qui ne porte aucun nom, n'est pas journalisée),
  `DECISION_CHANGED`, `RULE_CHANGED`, `SETTINGS_CHANGED`, `STAFF_NOTICE_CONFIRMED`, `STAFF_NOTICE_WITHDRAWN`, `ANALYSIS_STARTED`,
  `ENTRIES_SKIPPED`, `DIGEST_SENT` (courriel du jour : jour, nombres, destinataires, `renewed`, `renewedUpTo`), `FINDING_RENEWED` (un
  constat de reprise redevenu « nouveau »), et pour le mode exercice `EXERCISE_STARTED`, `EXERCISES_DELETED`, `EXERCISE_EMAIL_SENT`. Il ne garde que des valeurs validées (jamais le corps reçu d'une requête), des nombres, des codes, des identifiants.
- Avis (lot L5, forme réservée) : `{ percent | null, calibrated, measures, factors [{ label, effect }], summary, alternatives [],
  generatedAt, creditsUsed }`. **Le pourcentage est calculé par le serveur** (part de constats semblables qui ont été confirmés), l'IA rédige
  le résumé et les autres explications ; `null` quand les cas tranchés manquent ; mention « estimation non étalonnée ». 1 unité IA par avis.

## 7. Réglages et interrupteurs par pays et région (`Services/LossCountryPolicy`, `LossSettingsService`)

`{ country, region, countryLabel, regionLabel, staffNotice { required, confirmedAt, confirmedBy, template { title, text } }, personPatterns { allowed, enabled, default,
note }, cashierAlerts { allowed, enabled, availableFrom: "L5" }, video { …, availableFrom: "L3" }, retention { findingsMonths,
confirmedMonths, purge: "none", bounds, defaults }, never [{ code, label }], legalNotice }`. Pays et région viennent de l'adresse de
l'entreprise (normalisés comme pour les taxes), en lecture seule ici ; `countryLabel` et `regionLabel` sont leurs noms dans la langue
demandée (« Canada », « Québec » / « Quebec » ; `LossPlaceLabels` : noms de pays de Symfony, table des provinces du Canada et des États des
États-Unis), `null` quand le code est absent ou inconnu : les codes ne changent pas.

| | Notice aux salariés | Règles par personne |
|---|---|---|
| France (départements et régions d'outre-mer compris), Québec | **exigée** : aucune analyse avant la confirmation du gérant | non permises (jusqu'à l'avis d'un avocat) |
| pays illisible (champ libre de la fiche : vide, « FRA », un nom…), Canada sans province reconnue | **exigée, dans le doute** | non permises |
| États-Unis | rappel | permises ; **allumées d'office en Floride**, éteintes par défaut ailleurs |
| autres | rappel | non permises tant que rien n'est décidé (choix prudent) |

Le texte modèle de la notice (`staffNotice.template`, rendu par les réglages) ne dit que ce que fait le système : les constats sont vus par
l'administrateur de la boutique seulement, et l'ouverture de la fiche d'un constat est enregistrée, avec le compte et le jour.
Conservation (décision) : constats non confirmés 24 mois après la décision, confirmés 5 ans (60 mois) ; durées enregistrées, **aucune purge**.
À faire relire par un avocat avant l'activation chez un vrai client.

## 8. Routes (`/api/gestion/pertes`, `Controller/LossPreventionController`)

| Méthode, route | Entrée | Réponse |
|---|---|---|
| `GET` (racine) | `from`, `to` (journées du magasin ; défaut : 30 derniers jours), `analyse=0` (rafraîchissement sans analyse) | `{ period, lastAnalysis { at, pending, blocked, skipped, ran, failed }, counts { open, watch, new, active, byStatus [], byRule [], byRegister [] }, amountAtStake, currency, cashDesk { closings, discrepancy, discrepancyCount }, staffNotice { required, confirmedAt }, caveats [], screen }` (aucun nom : celui qui a confirmé la notice n'est rendu que par les réglages). `cashDesk` : mêmes définitions que les statistiques (égalité testée au cent près) |
| `GET /constats` | `status` (plusieurs, séparés par des virgules), `rule`, `from`, `to`, `cashRegisterId`, `isNew`, `sort` (`date` par défaut, `amount`), `page`, `limit` (≤ 100) | liste de lignes, sans aucun nom ; en-têtes `X-Total-Count`, `X-Page`, `X-Limit` |
| `GET /constats/{id}` | — | la fiche ; consultation journalisée (une fois par administrateur et par jour), `isNew` éteint ; 404 `FINDING_NOT_FOUND` |
| `PUT /constats/{id}/decision` | `{ status, note? }` | la fiche complète ; 422 `fields.status: INVALID_VALUE`, `fields.note: REQUIRED` |
| `GET /regles` | — | `{ rules [{ code, label, description, wave, enabled, params [{ key, label, type, value, default, min, max }], personVariant { allowed, enabled, label, description, params } \| null }], notice, screen }` |
| `POST /regles/apercu` | `{ code, enabled?, params?, personVariant? }` (lot L1-b) | `{ code, current, wouldOpen, days: 30, truncated, skipped, sample [{ summary, storeDay, amountAtStake, personPattern }] (3 au plus), notice }` : nombre de constats que la règle ouvrirait sur 30 jours avec ses réglages actuels et avec ceux proposés. **Aucune écriture** : les vraies règles sont jouées, leurs constats vont dans un puits en mémoire (`LossPreviewSink`) ; les deux nombres se comparent (ni l'un ni l'autre ne tient compte des constats déjà là). Lecture bornée à 1 000 entrées, les plus récentes (`truncated` au-delà), jamais sous le plancher (mesure sur la base de test : environ 2,3 ms par entrée et par jeu pour une règle qui relit sa session, soit de l'ordre de 5 s au pire à la borne). Une règle en échec sur une entrée saute cette entrée : l'aperçu aboutit quand même et le dit (`skipped`, nombre d'entrées sautées) ; ce qu'elle avait posé dans le puits avant d'échouer n'est pas compté (`LossPreviewSink::snapshot` / `restore`). Mêmes blocages que l'analyse (409) |
| `PUT /regles/{code}` | `{ enabled?, params?, personVariant? { enabled?, params? } }` | la règle ; 404 `RULE_NOT_FOUND` ; 422 `PARAM_OUT_OF_RANGE`, `UNKNOWN_PARAM`, `MUST_BE_INTEGER`, `MUST_BE_BOOLEAN`, `NOT_ALLOWED_IN_COUNTRY`, `NO_PERSON_VARIANT` |
| `GET /reglages`, `PUT /reglages` | `{ staffNoticeConfirmed?, personPatterns? { enabled }, retention? { findingsMonths?, confirmedMonths? } }` | les réglages ; 422 comme ci-dessus |
| `POST /analyse` | — | `{ status: done \| busy, read, opened, completed, skipped, pending }` ; 409 `MODULE_NOT_GRANTED` (droit non accordé, même en mode « demo ») ou `STAFF_NOTICE_REQUIRED` |

Erreurs : celles de la gestion (`GestionValidationException` → 422 `VALIDATION_FAILED` avec `fields { champ: code }` ;
`GestionConflictException` → `{ error, code, message }`). Codes par champ : `INVALID_JSON` (corps), `INVALID_VALUE` (état, règle, tri, poste,
paramètre en tableau), `INVALID_DATE`, `BEFORE_FROM`, `REQUIRED` (note), `TOO_LONG` (note de plus de 2 000 caractères), `MUST_BE_STRING`,
`MUST_BE_BOOLEAN`, `MUST_BE_INTEGER`, `MUST_BE_OBJECT`, `PARAM_OUT_OF_RANGE`, `UNKNOWN_PARAM`, `NOT_ALLOWED_IN_COUNTRY`, `NO_PERSON_VARIANT`.
Une valeur illisible (texte, paramètre en tableau, `page=abc`) rend toujours 422, jamais une erreur interne ni un défaut silencieux ;
`page` et `limit` démesurés sont ramenés à leurs bornes (100 000 et 100).
La garde du module lit le chemin décodé, comme le routeur : `/api/gestion/%70ertes` est la même route. Codes d'écran (contrat avec les fronts) : `pertes.home`, `pertes.constats`,
`pertes.constat` (paramètre id), `pertes.regles`, `pertes.reglages`, `pertes.exercices` (lot EX-1) ; réservés : `pertes.installation`, `pertes.installation.etape`.

### Mode exercice (lot EX-1, `Services/LossExerciseService`)

Décisions du propriétaire (14/11/2026) : le gérant lance un **constat fictif** pour voir à quoi ressemble un vrai constat et comment y
répondre. Ouvert **sans le droit du module** (démonstration avant achat) et **avant la notice aux salariés**, France et Québec compris ;
plafond de **30 constats d'exercice par site** ; **effacement manuel seulement**, d'un geste ; comptes fictifs « Caissier d'exercice » et
« Responsable d'exercice » ; l'alerte est montrée à l'écran et, sur demande, envoyée en vrai courriel « [Exercice] » au seul administrateur
qui lance.

| Méthode, route | Entrée | Réponse |
|---|---|---|
| `GET /exercices` | — | `{ scenarios [{ code, label, description, learn, rule { code, label }, personPattern, available }], findings [lignes de liste], count, limit: 30, remaining, daily { limit: 30, remaining, resetAt }, notice, screen: "pertes.exercices" }` |
| `POST /exercices` | `{ scenario, sendTestEmail? }` | 201 `{ finding (la fiche, `exercise: true`), alertPreview { tile { title, text }, email { subject, message } }, testEmail { requested, sent, reason: NO_RECIPIENT_ADDRESS \| NOT_DELIVERED \| DAILY_LIMIT_REACHED \| null }, remaining, screen }` ; 422 `fields.scenario: REQUIRED \| INVALID_VALUE`, `fields.sendTestEmail: MUST_BE_BOOLEAN` ; 409 `EXERCISE_DAILY_LIMIT` (`limit`, `resetAt`), `EXERCISE_LIMIT_REACHED` (`limit`), `EXERCISE_SCENARIO_NOT_AVAILABLE` (`scenario`) |
| `GET /exercices/{id}` | — | la fiche du constat d'exercice (même forme qu'un vrai) ; 404 `FINDING_NOT_FOUND` pour un vrai constat |
| `PUT /exercices/{id}/decision` | `{ status, note? }` | la fiche ; mêmes règles que la décision d'un vrai constat ; 404 pour un vrai constat |
| `DELETE /exercices` | — | `{ deleted }` : tous les constats d'exercice du site, avec leurs preuves et leurs lignes de journal |

- **Scénarios** (`LossExerciseService::SCENARIOS`) : `cash_shortage`, `drawer_no_sale`, `void_quick`, `void_series`, `refund_no_receipt`,
  `refund_cash_for_card`, `discount_vague`, `price_override`, `approval_refused`, et trois variantes par personne (`cash_shortage_series`,
  `refund_series`, `discount_series`), `available` seulement là où le pays permet les règles par personne. Chaque scénario prend ses chiffres
  dans les **seuils du site** (un manque juste au-dessus du seuil, une ouverture du tiroir de plus que le maximum…).
- **Rien dans la caisse** : les règles réelles ne sont pas jouées ; le constat et ses preuves sont écrits à partir du scénario. Les preuves
  ne renvoient à aucune opération (`ref { type: "exercise", id: rang, sequence: null, hash: null }`) ; la fiche d'un exercice ne relit rien
  dans la caisse : motifs et noms d'articles sont des textes du scénario, gardés dans la copie par leur **clé** (`reasonText`,
  `articleText`) et rédigés à la lecture. L'analyse réelle n'est pas lancée ; la ligne `loss_setting` d'un site sans le module n'est pas créée.
- **Aucune personne réelle** : identifiants négatifs (`LossFindingReader::EXERCISE_CASHIER` -1, `EXERCISE_MANAGER` -2), nommés par un
  libellé ; la table des comptes n'est pas lue pour eux.
- **Jamais dans un chiffre réel** : `loss_finding.exercise` écarte ces constats de la synthèse, de la liste, de la vignette, du courriel du
  jour (ni annoncés, ni dans sa borne), des constats à traiter et de la fenêtre des règles par personne (`exercise = false` dans chaque
  requête qui compte ou liste, dans `LossFindingRepository` et `LossHomeTile`) ; l'aperçu d'un seuil ne lit pas les constats. Deux lectures
  rendent la ligne telle quelle, `row()` et `byKey()` : c'est leur appelant qui garde (la fiche et la décision refusent le mauvais genre ;
  une clé d'exercice commence par `EXERCISE:`, aucune règle ne la produit).
- **Vrais constats et exercices ne se croisent sur aucune route** : un exercice est « inconnu » (404) de `GET /constats/{id}` et de
  `PUT /constats/{id}/decision`, un vrai constat l'est des routes d'exercice ; `GET /constats` ne rend jamais un exercice.
- **Garde** : `LossPreventionModuleSubscriber::OPEN_ROUTES` nomme les cinq routes en toutes lettres (méthode et chemin exact, lu par
  `RequestPath::of`) ; elles passent sans le droit du module. La garde de la gestion et le rôle d'administrateur restent exigés ; tout le
  reste de la section reste fermé sans le droit.
- **Plafonds** : 30 constats d'exercice PRÉSENTS par site (409 `EXERCISE_LIMIT_REACHED`, `limit`), et **30 lancements par jour du magasin
  et par site** (409 `EXERCISE_DAILY_LIMIT`, `limit`, `resetAt` : le début de la journée suivante du magasin, en UTC) : effacer ne rend
  aucun lancement, « effacer puis relancer » n'est donc pas illimité sur des routes ouvertes sans le droit du module. 10 courriels
  d'exercice par jour et par site : au-delà, l'exercice est lancé, `testEmail.reason` vaut `DAILY_LIMIT_REACHED`. Les comptes du jour sont
  lus au journal du module (lignes `EXERCISE_STARTED`, `EXERCISE_EMAIL_SENT`, par l'index du type, jamais au-delà du plafond). `GET
  /exercices` rend `daily { limit, remaining, resetAt }`. Dans les tests, les plafonds par jour sont relevés (`config/services_test.yaml`).
- **Verrou** : tout ce qui écrit pour un constat d'exercice (lancement, lecture d'une fiche, qui journalise la consultation, décision,
  effacement) se fait dans une transaction qui tient le verrou consultatif des exercices (`LossFindingRepository::EXERCISE_LOCK`) : un
  effacement ne peut ni échouer sur une écriture arrivée entre deux de ses suppressions, ni laisser une ligne de journal d'un exercice disparu.
- **Numéros** : un exercice porte un numéro **négatif**, l'opposé de son rang ; le rang est lu dans la dernière ligne `EXERCISE_STARTED` du
  journal du module (jamais supprimée : il ne redescend pas, même après un effacement), dans la même transaction que le constat, sous un
  verrou consultatif propre aux exercices. Les vrais numéros se suivent parmi les vrais constats seulement.
- **Effacement** : `LossFindingRepository::deleteExercises()`, une transaction, `SET LOCAL loss.purge = 'exercise'`, dans l'ordre preuves,
  journal, constats. **La base garantit elle-même** qu'un vrai constat ne peut pas partir par ce chemin (migration `Version20270105100000`) :
  marque `exercise` ET identifiant du constat figés à la création (preuves et journal désignent leur constat par son identifiant, et la
  porte lit la marque par lui) ; la porte `on` de L1-a n'existe plus ; avec la porte `exercise`, seule une ligne dont le constat est
  marqué exercice se supprime ; un constat d'exercice ne se supprime qu'après ses preuves et ses lignes de journal ; une ligne de journal
  sans constat ne se supprime jamais ; le vidage des trois tables est refusé. Le journal garde pour toujours `EXERCISE_STARTED` (rang,
  scénario), `EXERCISES_DELETED` (nombre) et `EXERCISE_EMAIL_SENT` (rang), sans constat rattaché ni adresse.
- **Retour arrière** : un code d'avant EX-1 ne connaît pas la marque `exercise` ; sur une base migrée, un exercice resté en base y
  deviendrait un vrai constat. AVANT de remettre en ligne un tel code : `php bin/console app:pertes:exercices:effacer --all`
  (`--dry-run` pour compter, `--tenant=<code>` pour un site ; `Command/LossExercisePurgeCommand`), qui efface par la porte, site par site,
  et sort en échec si un site est en erreur (`docs/mise-en-ligne.md`, § 5). La migration peut rester ; si on la défait, `down()`
  (`DOWN_STATEMENTS`) purge lui aussi les exercices avant de retirer la colonne.
- **Mots** : en anglais, « Practice » pour le mode et son écran (menu, titre du guide), « exercise » pour l'élément (« An exercise is a
  fictitious finding… », « [Exercise] … », notion « Exercise (loss prevention) »), « Practice cashier » et « Practice manager » pour les
  deux comptes fictifs ; jamais « drill » (décision du 15/11/2026 ; un test le garde). L'aperçu de l'alerte d'un constat sans montant (tiroir ouvert sans vente,
  validations refusées) dit « 1 nouveau constat à vérifier. », sans « 0,00 $ en jeu » : même règle que la vraie vignette et le vrai
  courriel du jour, dont le montant est la somme des constats qui en portent un.
- **Courriel d'exercice** : gabarit du courriel du jour avec le bandeau « EXERCICE » (`exerciseBanner`), objet préfixé « [Exercice] », lien
  vers l'écran des exercices (`AdminSpaceUrl::to('pertes.exercices')`) ; il
  n'entre pas dans le « un par jour » (ni `last_digest_day` ni la borne ne bougent). Sans configuration d'envoi : `sent: false`,
  `reason: NOT_DELIVERED`.
- **Limite connue du courriel du jour** : le rang des constats « complétés » (`renewedUpTo`) est écrit dans la ligne `DIGEST_SENT`, après
  l'envoi ; si cette écriture échouait après un envoi réussi, ces constats seraient annoncés une seconde fois le lendemain. Le compte des
  constats nés et des constats complétés se lit sur une même photo de la base (transaction en lecture répétable).

## 9. Base

Migration `Version20270101100000` (tables et colonne nouvelles seulement, idempotente) : `entreprise.is_loss_prevention_active` ;
`loss_setting` (une ligne par site : notice, règles par personne, conservation, curseur, plancher d'analyse) ; `loss_rule` (réglages changés par le site) ;
`loss_finding` ; `loss_evidence` ; `loss_journal` ; déclencheurs d'inaltérabilité. Puis `Version20270102100000`
(`loss_setting.last_digest_day`), `Version20270103100000` (`loss_setting.last_digest_finding_id`) et `Version20270105100000` (lot EX-1 :
`loss_finding.exercise`, index `idx_loss_finding_exercise` et `idx_loss_journal_type`, marque d'exercice figée, porte de purge réduite aux
constats d'exercice, constat non supprimable sauf exercice). Entités déclarées dans `CacheTags::NO_CACHE_READ`
(lues et écrites en SQL par leurs dépôts). **À la mise en ligne, la colonne doit exister dans chaque base AVANT le code** :
`ModuleAccessService::COLUMNS` la lit.

## 10. Tests

`tests/Functional/LossPrevention/` (socle `LossPreventionTestCase`). `LossPreventionApiTest` : chaque règle sur une journée de caisse jouée par les vraies routes de la caisse ;
analyse rejouée depuis le début sans doublon ; journal de caisse inchangé et intact ; égalité avec le bloc cashDesk ; aucun nom dans les
résumés, la liste et la synthèse, aucun courriel dans une fiche ; décision et historique ; bornes des paramètres ; seuil changé ; notice
bloquante au Québec et dans le doute, rien de ce qui la précède n'est analysé ; aucun texte libre dans une preuve ni au journal ; règles par
personne en Floride ; rôles ; double garde du module, chemin encodé compris ; preuves et
journal inaltérables ; commande du minuteur. `LossPreventionAnalysisTest` : curseur sur un lot plein, seuils au cent près, clôture sans
comptage, annulations hors du champ, règle par personne au cran près et rejeu de deux fenêtres, verrou, reprise, analyse à la lecture de la
synthèse, filtres et comptes, règle en échec jamais silencieuse, un site en erreur parmi plusieurs, aucune analyse sans le droit du module
même en mode « demo », session sans heure d'ouverture. `LossPreventionReinforcementTest` : renforts de la relecture finale de L1-a (huit modifications du code rejouées). `LossPreventionGestionLinksTest`
(lot L1-b) : vignette de l'accueil, courriel de résumé du jour (contenu sans nom ni lien, un par jour, préférence du gérant), guides des cinq
écrans. `LossPreventionWave2Test` (lot L1-b) : retours inhabituels, remises au motif vague,
prix baissés à la main, validations refusées sur une tablette, variantes par personne en Floride, aperçu d'un réglage sans aucune écriture,
vente hors ligne synchronisée en retard lue par le curseur et datée à son heure réelle. `LossPreventionExamplesTest` : **exemples
de réponse, un fichier JSON par route et par refus, dans `tests/Fixtures/pertes/`** (jeux d'essai des écrans du web et du mobile) ; le test
vérifie que chaque exemple a toujours la forme de la réponse du serveur ; régénération : en-tête du test (`UPDATE_LOSS_EXAMPLES=1`).
`LossPreventionExerciseTest` (lot EX-1) : chaque scénario donne un constat lu comme un vrai, sans une ligne dans la caisse ni un nom réel ;
aucun chiffre réel ne compte un exercice (synthèse, liste, vignette, courriel du jour, constats à traiter, aperçu, cheminement, règles par
personne) ; les quatre croisements de routes rendent 404 ; ouvert sans le droit du module et avant la notice, rien d'autre ne s'ouvre ;
plafond de 30 et numéros qui ne reviennent pas ; **la base refuse elle-même**, sans passer par le code du module, de supprimer quoi que ce
soit d'un vrai constat (et le mauvais ordre, l'ancienne porte, le changement de marque, le vidage) ; courriel d'exercice au seul demandeur.
Jeux d'essai en base de test seulement : **rien n'est écrit sur `demo`** (le journal de caisse est inaltérable, une journée d'essai ne s'y
défait pas).

## 11. Lots suivants (contrat réservé)

- **L1-b** (codé, voir les points « lot L1-b » ci-dessous) ; prévu à l'origine : champ d'activation et étapes dans EasyAdmin (une étape du cheminement global, un groupe propre au module) ; avec
  « appli degestion backend » : codes d'écran dans `ConsoleScreens`, guides des écrans, vignette de l'accueil « N nouveaux constats, X en
  jeu », termes du lexique ; courriel de résumé, au plus un par jour, réglable par gérant, sans aucun nom.
- **Garde bloquante (chef de projet, 14/11/2026)** : `LossCountryPolicy::personPatternsAllowed` refuse d'abord tout endroit où la notice aux
  salariés est exigée, quelle que soit la liste des pays permis ; `LossCountryPolicyTest` échoue si cette garde saute ou si un territoire
  français entre dans la liste. Elle ne se lève qu'après la correction ci-dessous.
- **À borner le jour où les règles par personne s'ouvrent en France ou au Québec** : `CashShortageSeriesRule` relit les clôtures des N derniers
  jours dans `cash_register_report` sans tenir compte du plancher de la notice (`analysis_floor_sequence`). Sans effet aujourd'hui : les
  règles par personne ne sont permises qu'aux États-Unis, où la notice n'est pas exigée. Même remarque pour `RefundSeriesRule` et
  `DiscountSeriesRule`, qui relisent les retours et les ventes d'une fenêtre.
- **Dans la gestion (lot L1-b)** : les cinq codes d'écran sont déclarés dans `ConsoleScreens` (thèmes `accueil` et `reglages` ; ils proposent
  les analyses générales de leur thème, aucune analyse propre aux constats : la console ne chiffre rien sur les constats) et ont leur guide (`translations/ecrans.*`, `GET /api/gestion/ecrans/{code}/guide`) ; la vignette d'accueil
  `loss-findings` (`Services/LossHomeTile`, `docs/vignettes-gestion.md`) dit « N nouveaux constats à vérifier, X en jeu », sans aucun nom ;
  absente quand l'analyse est arrêtée. Le droit du module y est d'abord lu dans les indicateurs de modules en cache
  (`ModuleAccessService::flags()`) : un site sans le module ne coûte aucune requête de plus à l'accueil.
- **Courriel de résumé du jour (lot L1-b, `Services/LossDigestService`)** : après le passage de l'analyse planifiée sur un site, **à partir de
  8 h à l'heure du magasin** (`SEND_HOUR`, lue par l'horloge du site : le premier passage du minuteur après 8 h envoie), s'il y a des
  constats nouveaux **nés depuis le courriel précédent** et qu'aucun courriel n'est parti ce jour du magasin (`loss_setting.last_digest_day`,
  migration `Version20270102100000` ; `loss_setting.last_digest_finding_id`, migration `Version20270103100000` : le dernier constat ANNONCÉ,
  lu dans la même instruction que le compte, posé dans la même instruction que le jour et rendu avec lui). Tout ce qui peut échouer
  (langue, textes, objet, hôte du site) est préparé AVANT la réservation ; une erreur pendant l'envoi, avant qu'un seul courriel soit
  parti, rend le jour et la borne et remonte. Décisions du propriétaire (14/11/2026) : aucun
  courriel les jours où rien n'est né, même s'il reste des constats ouverts (la vignette de l'accueil continue de les rappeler) ; un constat
  né après l'envoi part le lendemain. **Un constat de reprise redevenu « nouveau »** (une preuve postérieure à la première analyse l'a
  complété) est annoncé lui aussi dans le courriel du jour suivant, à part (« complété par une nouvelle preuve » : ligne du tableau et
  phrase du message), **une seule fois par retour à « nouveau »** : le retour est écrit au journal du module (`FINDING_RENEWED`,
  `LossFindingWriter::complete`), le courriel annonce ceux qui sont postérieurs au rang noté par le courriel précédent (`renewedUpTo` dans sa
  ligne `DIGEST_SENT`) et encore jamais ouverts. Un courriel part aux administrateurs du site (rôle `ROLE_ADMIN` exact, compte vérifié ; **pas le compte super administrateur de la plateforme**, qui porte ce rôle sur chaque base et recevrait le résumé de chaque site : décision du 15/11/2026, comme le résumé de la semaine ; un site sans autre administrateur ne reçoit pas le courriel, `no_recipient`, ses constats restent dans l'application) qui ont gardé le type `loss_digest` actif dans leurs
  préférences de notification de la gestion (canal `email`, `docs/notifications-gestion.md`). Par le mailer du site (file et worker des
  courriels existants, aucun handler nouveau). Contenu : nombre de constats nouveaux, montant en jeu, journée du magasin ; **aucun nom, aucun
  motif saisi à la caisse**. **Un seul lien**, la seule adresse du courriel : l'écran des constats nouveaux de l'espace d'administration
  (`AdminSpaceUrl::to('pertes.constats', ['isNew' => true])`, lot LIEN, `docs/liens-espace-administration.md` :
  `<adresse>/admin/ouvrir?screen=pertes.constats&isNew=1`) ; sans adresse connue pour le site, pas de lien, le texte dit où regarder.
  **Rien ne part quand l'analyse est arrêtée** (statut `not_allowed` : droit retiré, notice exigée non confirmée ou retirée). **Le jour est
  réservé AVANT l'envoi** par une écriture conditionnelle (`LossSettingRepository::reserveDigestDay` : de deux lancements qui se chevauchent,
  un seul l'obtient ; la même instruction verrouille la ligne et rend les valeurs d'avant, celles qu'un envoi en échec remettra), puis rendu si aucun envoi n'aboutit (pas de configuration d'envoi sur le site : on réessaiera). La dernière ligne du
  courriel est le nom du site, sous la clé `siteLine` : **jamais `signature`**, que l'envoi commun (`EmailSenderService::sendTemplatedEmail`)
  remplace par la signature de la configuration d'envoi du site (elle peut porter un nom de personne et un lien). Le domaine passé à
  l'envoi commun est l'hôte public du site (il sert à l'adresse du logo). Depuis le lot NOTIF-1 (15/11/2026) le résumé est un
  courriel de SUIVI (`docs/notifications-courriels.md`) : pour chaque administrateur, son niveau décide s'il part, s'il est retenu
  (niveau « Essentiel » : il attend le résumé de la semaine) ou s'il ne part pas (niveau « Aucune ») ; chaque destinataire servi ou
  retenu a sa ligne dans `notification_log` (canal `email`). Le jour et la borne sont posés dès qu'un destinataire est servi OU retenu
  (statut `held` quand tous sont retenus) : le constat est annoncé une fois. Un destinataire dont l'envoi échoue alors que d'autres sont
  servis est retenu lui aussi (`send_failed`). Le lancement à la main (`--courriel-maintenant`) ignore aussi la règle des 8 h de la
  décision d'envoi. Si la décision d'envoi est en panne, le résumé part quand même, à tous. **Conséquence à connaître** : tant qu'un
  site est au niveau « Essentiel » (le niveau automatique d'un site d'essai, donc de tous les sites tant que la plateforme est en mode
  « demo »), le résumé du jour ne part plus par courriel : il est retenu chaque jour, lisible dans l'application, jusqu'au résumé de la
  semaine du lot NOTIF-2. Un administrateur qui veut le courriel du jour choisit le niveau « Normal » ou « Tout » dans ses notifications,
  ou la plateforme règle le niveau du site. Le journal du module garde `DIGEST_SENT` (jour, nombres, nombre de destinataires, `held`). Textes `translations/emails.*`
  (`loss_digest.*`), gabarit `templates/emails/loss_digest.html.twig`.
- **Cheminement du site (lot L1-b)** : quand le droit est accordé, le groupe `module.loss_prevention` de `SiteReadinessService` porte cinq
  étapes : `loss.management` (la gestion est activée ; bloquante), `loss.place` (pays de l'adresse lisible ; bloquante), `loss.staff_notice`
  (notice confirmée ; bloquante là où elle est exigée), `loss.rules` (au moins une règle réglée), `loss.first_analysis` (une analyse a eu
  lieu) ; `appScreen` : `pertes.reglages`, `pertes.regles`, `pertes.home`. Sans le droit : l'étape générique « Obtenir ».
- **Une lecture n'écrit rien** : la ligne `loss_setting` d'un site n'est créée qu'au premier enregistrement ou à la première analyse ; une
  lecture (synthèse, réglages) rend les valeurs par défaut. Un refus 403 `MODULE_INACTIVE` ne touche à aucune table (test).
- **Mode exercice** : codé (lot EX-1, § 8, « Mode exercice »).
- **L2 à L6** : pièce à numéro unique par variante (`created`, `in_stockroom`, `on_floor`, `at_counter`, `sold`, `returned`, `missing`,
  `retired` ; zones `stockroom`, `stockroom_door`, `floor`, `counter`, `exit` ; un changement d'état n'existe que par un fait ; une pièce
  vendue garde ses puces actives ; la pièce ne remplace pas le stock) ; faits du boîtier en lots rejouables (`PIECE_CREATED`,
  `TAG_ASSOCIATED`, `PAIR_SET`, `SPOT_CHECK`, `COUNTER_OCCUPIED`, `DOOR_OPENED`, `BOX_HEARTBEAT` avec la liste des appareils vus,
  `SOURCE_DOWN`, `COUNT_DONE`, `ZONE_SEEN`, `TEST_TAG_READ`) ; assistant d'installation pas à pas (progression gardée par le serveur) ;
  avis et alertes (L5). Avant L2, un lot du catalogue à part : « code-barres de variante : remplir, rendre unique, imprimer » :
  **fait pour « remplir, rendre unique »** (lot CB-1, en ligne le 15/11/2026 : un code désigne une seule chose par site, garanti par la
  base ; codes générés à la demande) ; « imprimer » est fait aussi (lot CB-2, étiquettes à la réception, et lot CB-3, les mêmes gestes
  dans l'application de gestion ; en ligne le 15/11/2026). Fiche : `docs/codes-barres.md`. **Plan du lot L2 : § 13.**

## 12. Décisions du propriétaire (14/11/2026)

Après la relecture du lot (même jour), validé par le propriétaire : **A** là où la notice est exigée, seul ce qui suit sa confirmation est
analysé ; **B** aucune analyse sans le droit du module, même en mode « demo » de la plateforme ; **C** aucun texte libre dans une table
inaltérable (motifs lus dans la caisse à l'affichage, note de décision sur le constat seulement). Validé par le chef de projet : **D** la liste
n'est pas journalisée, une fiche l'est une fois par administrateur et par jour ; **E** la synthèse ne nomme pas celui qui a confirmé la
notice ; **F** un constat né de la reprise redevient « nouveau » à la première preuve récente ; **G** texte exact de la notice ; **H** la
remarque sur les opérations non analysées porte sur 30 jours et compte chacune une fois.

1 « constat » ; 2 clé `module.loss_prevention`, dépendante de la gestion ; 3 administrateur du site seulement ; 4 règles par personne :
interrupteur par pays et région, permises aux États-Unis, allumées d'office en Floride, éteintes en France et au Québec ; 5 seuils par
défaut validés ; 6 conservation 24 mois / 5 ans, extraits vidéo 30 jours, aucune purge automatique ; 7 reprise de 30 jours ; 8 notice
bloquante en France et au Québec ; 9 minuteur accordé ; 10 jeux d'essai en base de test seulement ; 11 pourcentage calculé par le serveur ;
12 pays et région ; 13 vignette et notification réservées ; 14 lot du catalogue avant L2 ; 15 relecture par un avocat avant tout vrai client.

## 13. Lot L2 : étiquettes à puce (plan validé par le propriétaire le 15/11/2026 ; L2-a et L2-b codés)

Le plan ci-dessous a été **validé par le propriétaire le 15/11/2026, avec toutes les propositions du point 13.8**. Il précise ce que
le § 11 réservait (« pièce à numéro unique par variante », faits `PIECE_CREATED`, `TAG_ASSOCIATED`, `PAIR_SET`, `TEST_TAG_READ`).
**Le lot L2-a est codé** (pièces, puces, association, recherche, puces simulées, synthèse) : son contrat exact est au point 13.9, qui
fait foi là où il précise le plan. **Le lot L2-b est codé** (étiquettes des pièces en planche ou en rouleau, assistant d'installation,
gamme de matériel) : son contrat est au point 13.10.
Lot à risque (données, preuves) : relecture indépendante et mutations avant la bascule.

**Avant le premier client (décision du propriétaire, 15/11/2026)** : la lecture d'une puce sur un article VENDU (la puce part avec le
client, ou reste lisible sur lui) demande l'avis d'un avocat, comme la décision 15 du § 12. Rien dans le code ne lit aujourd'hui une
puce après la vente.

### 13.1 Ce que L2 apporte, et ce qu'il n'apporte pas

- **Apporte** : une **pièce** (un exemplaire physique d'une variante) reçoit un numéro unique et une ou plusieurs **puces** ; le gérant
  associe la puce à la pièce en lisant l'une puis l'autre ; il retrouve ce que porte une puce ; il imprime l'étiquette de la pièce.
- **N'apporte pas** : aucun appareil ne parle au serveur (le boîtier et sa clé sont le lot L3) ; aucune lecture à distance, ni comptage,
  ni sortie de zone (antennes : lot L4) ; aucun constat nouveau (les règles sur les pièces viennent avec L3 et L4) ; **aucune écriture
  de stock, de vente ni de catalogue** : le module n'écrit que dans ses tables (§ 1), et la pièce ne remplace pas le stock.
- Sans boîtier ni antenne, une vente ne dit pas QUELLE pièce est partie (la caisse lit le code-barres de la variante). En L2 une pièce
  ne connaît donc que deux états : `created` et `retired`. Les autres états du § 11 (`in_stockroom`, `on_floor`, `at_counter`, `sold`,
  `returned`, `missing`) n'apparaissent qu'avec les faits des appareils.

### 13.2 Modèle (tables du module seulement, une migration additive)

- **`loss_piece`** : `id`, `number` (numéro lisible, unique sur le site, tiré d'une séquence, jamais redonné), `variant_id`
  (obligatoire : la pièce est un exemplaire d'UNE variante), `product_id` (copie de lecture), `status`, `zone` (null en L2),
  `commande_id` (null si la pièce vient du stock déjà en rayon), `exercise`, dates. Le code-barres n'est PAS recopié : il se lit
  sur la variante (règle du lot CB-1 : le code d'une variante est celui de la pièce vendue), l'article quand elle est sa seule variante.
- **`loss_tag`** : `id`, `identifier` (forme canonique : hexadécimal en majuscules, sans séparateur), `raw` (ce que le lecteur a rendu),
  `technology` (`uhf`, `nfc`, `simulated`), `piece_id`, `status`, dates. **Un identifiant désigne une seule puce sur le site : index
  unique en base**, comme pour les codes-barres ; deux lectures simultanées de la même puce sont départagées par la base.
- **`loss_piece_event`** (inaltérable, comme `loss_journal`) : un fait par ligne (`PIECE_CREATED`, `TAG_ASSOCIATED`, `TAG_RETIRED`,
  `PAIR_SET`, `PIECE_RETIRED`, `TEST_TAG_READ`), qui, quand, d'où (application, exercice), clé d'idempotence. **Un état ne change que
  par un fait** ; `loss_piece.status` et `loss_tag.status` sont la projection des faits, recalculable.
- **Cycle de vie d'une puce** : `known` (lue pendant l'essai de l'assistant, pas encore posée) → `associated` (posée sur une pièce) →
  `retired` (détachée, détruite, perdue ; motif obligatoire). **Une puce associée n'est jamais redonnée à une autre pièce** : son
  histoire reste celle d'une seule pièce (question 3). Une pièce peut porter plusieurs puces (une paire de chaussures : `PAIR_SET`) ;
  une pièce vendue garde ses puces actives (§ 11, question 5).
- **Lien avec le stock** : aucun. La synthèse montre l'écart « pièces suivies / stock » par variante, pour information.

### 13.3 Routes (toutes sous `/api/gestion/pertes`, administrateur, garde du module)

| Route | Rôle |
|---|---|
| `GET /pieces`, `GET /pieces/{id}` | liste paginée (variante, état, avec ou sans puce) ; fiche d'une pièce, ses puces, ses faits |
| `POST /pieces` `{ variantId, count, commandeId? }` | crée N pièces d'une variante (500 au plus par appel) : à la réception d'une commande ou pour le stock déjà en rayon |
| `POST /puces/associer` `{ barcode \| variantId \| pieceId, identifier, technology }` | LE geste : lire le code-barres (ou choisir la pièce), lire la puce ; crée la pièce si besoin et pose la puce ; idempotent ; refus `TAG_ALREADY_ASSOCIATED { holder }`, `TAG_INVALID`, `BARCODE_NOT_FOUND`, `PIECE_RETIRED` |
| `GET /puces/recherche?identifier=` | ce que porte une puce : la pièce, sa variante, son état (ou rien) |
| `POST /puces/{id}/retirer`, `POST /pieces/{id}/retirer` `{ reason }` | retirer une puce, retirer une pièce (confirmation exigée, comme `replace` du lot CB-3) |
| `POST /pieces/etiquettes/pdf` `{ pieceIds, format }` | étiquette de la pièce : code-barres de la variante, numéro de la pièce, nom, prix au choix ; formats `a4-50x30` (lot CB-2) et `roll-50x30` (une étiquette par page, pour une imprimante thermique à rouleau) |
| `GET /pieces/synthese` | par variante : stock, pièces par état, pièces avec puce, écart |
| `GET /installation`, `POST /installation/etapes/{code}/verifier`, `POST /installation/puce-test` | assistant d'installation (13.5) |
| `GET /materiel` | gamme de matériel par besoin et par niveau (13.6) |
| `POST /exercices/puces`, `DELETE /exercices/pieces` | puces et pièces simulées du mode exercice (13.4) |

Textes en français et en anglais par le serveur (`?locale=`), erreurs à la forme de la gestion, codes d'écran (`pertes.pieces`,
`pertes.installation`) déclarés dans `ConsoleScreens` avec leur guide. Le mobile et le web utilisent les mêmes routes.

### 13.4 Sans matériel : puces simulées

- Technologie `simulated` : le serveur fabrique des identifiants `SIM-…` (une forme qu'aucune vraie puce ne rend) ; l'application
  montre « Simuler une lecture » à la place du lecteur. Tout le parcours (créer, associer, chercher, retirer, étiquette, assistant) se
  joue ainsi sans rien acheter : mode exercice, démonstration avant achat, tests automatiques.
- Mêmes règles que le lot EX-1 : ouvert sans le droit du module, plafonné, effacé d'un geste, `exercise = true` sur la pièce, la puce
  et les faits, **jamais dans un chiffre réel** (synthèse, liste, futurs constats). Une vraie pièce refuse une puce simulée, et l'inverse.

### 13.5 Assistant d'installation

- Une suite d'étapes gardée par le serveur, sur le modèle du guide des séquences de la gestion (`docs/sequences-gestion.md` : l'avancement
  se calcule à partir des données, jamais d'une case cochée) : `GET /installation` rend
  `{ steps [{ code, title, text, image, status: todo | current | done, verify { kind, label } }], progress }`.
- Étapes de L2 : 1 choisir son niveau de matériel (13.6) ; 2 imprimer une étiquette d'essai ; 3 lire une puce d'essai (« Vérifier » :
  le serveur a reçu un fait `TEST_TAG_READ` lisible, de forme attendue, pour une puce encore libre ; sinon il dit quoi essayer :
  lecteur réglé en clavier, octets inversés, puce déjà posée) ; 4 poser la puce sur une première pièce (« Vérifier » : une pièce réelle
  porte une puce associée) ; 5 terminé. Les étapes du boîtier (L3) et des antennes (L4) s'ajouteront à la même suite.
- `image` : une clé d'illustration par étape. Des dessins tant que le matériel n'est pas choisi ; des photos du vrai matériel ensuite
  (question 6). « Vérifier » ne coche rien : il relit les données et répond `{ verified, hint }`.

### 13.6 Gamme de matériel, niveau bricoleur compris

- `GET /materiel` : pour chaque **besoin** (« lire une puce », « imprimer l'étiquette », « poser la puce »), plusieurs **niveaux**
  (`pro`, `standard`, `bricoleur`) avec ce qu'il faut, ce que ça permet et ses limites. Des textes servis par le serveur, sans prix ni
  vente (l'offre et la vente du matériel restent au lot L6).
- Niveaux prévus pour L2 : **pro** : imprimante qui imprime et écrit la puce d'un geste, lecteur à main ; **standard** : puces
  autocollantes déjà numérotées en usine, imprimante thermique ordinaire, lecteur de bureau branché en clavier ; **bricoleur** : les mêmes
  puces autocollantes, étiquettes sur feuilles A4, lecture par le téléphone (puces NFC) ou par un module lecteur monté sur une carte de
  type Arduino, branché en clavier. Aucun niveau n'envoie rien au serveur de lui-même en L2 : la lecture passe par l'application.
- Le modèle ne dépend pas du niveau : une puce est un identifiant et une technologie.

### 13.7 Risques

1. **Identité** : une puce pour deux pièces, ou deux lectures au même instant. Parade : index unique en base, refus avec ce qui porte
   déjà la puce, test à deux connexions (comme le lot CB-1).
2. **Preuve** : un état modifié après coup ôterait sa valeur au dossier. Parade : faits inaltérables, état recalculable, aucun
   changement d'état hors d'un fait.
3. **Vie privée** : une puce reste lisible sur un article vendu. Parade proposée : puce sur l'étiquette détachable, affichette en
   magasin, point à faire relire par l'avocat (décision 15) avant tout vrai client.
4. **Lecteurs** : formats différents pour une même puce (octets inversés, décimal, préfixes). Parade : forme canonique, valeur brute
   gardée, étape d'essai de l'assistant qui détecte les cas connus.
5. **Confusion avec un code-barres** : un lecteur branché en clavier écrit là où est le curseur ; un identifiant de puce a la forme
   d'un code-barres acceptable. Parade : champs distincts, refus de poser comme code-barres un identifiant de puce connu, garde
   `replace` du lot CB-3.
6. **Volume** : des milliers de pièces par boutique. Parade : listes paginées, créations plafonnées, index.
7. **Matériel jamais vu** : tout est bâti sur des puces simulées. Parade : une campagne d'essai sur du vrai matériel avant d'ouvrir
   à un client, quand le propriétaire aura choisi un lecteur.

### 13.8 Questions au propriétaire, avec la proposition

1. **Technologie** : puces lisibles à distance (UHF : nécessaires aux antennes et au comptage du lot L4, illisibles par un téléphone)
   ou puces NFC (lues par le téléphone, à quelques centimètres) ? *Proposition : le modèle accepte les deux ; l'offre vise l'UHF ; le NFC
   reste le niveau d'entrée, pour associer et contrôler seulement.*
2. **Étiquette** : une étiquette qui porte tout (imprimante spéciale) ou l'étiquette de code-barres d'aujourd'hui plus une puce
   autocollante numérotée en usine ? *Proposition : la seconde en L2, aucune écriture dans la puce ; l'imprimante spéciale reste le
   niveau pro, plus tard.*
3. **Réemploi** : une puce détachée peut-elle resservir sur une autre pièce (antivols rigides) ? *Proposition : non en L2, puces
   jetables seulement ; le réemploi demandera sa propre règle.*
4. **Quoi suivre** : toutes les pièces, ou seulement les articles choisis ? *Proposition : au choix du gérant, article par article
   (rien par défaut) ; suivre tout coûte cher en puces et en gestes.*
5. **Article vendu** : la puce part-elle avec le client ? *Proposition : puce sur l'étiquette détachable, affichette, avis de l'avocat.*
6. **Images de l'assistant** : *proposition : des dessins maintenant, des photos quand le matériel sera choisi (fournies par le
   propriétaire ou par le fournisseur).*
7. **Qui associe** : l'administrateur seulement, ou aussi un magasinier ? *Proposition : l'administrateur seulement en L2 (règle du
   module) ; un droit de magasinier avec le boîtier, au lot L3.*
8. **Découpage** : *proposition : L2-a (pièces, puces, association, recherche, puces simulées, synthèse), puis L2-b (étiquettes en
   rouleau, assistant d'installation, gamme de matériel).*

Réponse du propriétaire (15/11/2026, relayée par le chef de projet) : les huit propositions sont retenues ; la gamme a trois niveaux
(pro, standard, bricoleur), et le niveau bricoleur se vend sans matériel.

### 13.9 Lot L2-a codé : contrat des routes (15/11/2026)

Code : `Controller/LossPieceController`, `UseCase/LossPieceUseCase`, `Services/LossPieceService`, `Services/LossTagIdentifier`,
`Repository/LossPieceRepository`, entités `LossPiece`, `LossTag`, `LossPieceEvent`. Migration `Version20270109100000` (tables
`loss_piece`, `loss_tag`, `loss_piece_event`, séquences `loss_piece_number_seq` et `loss_piece_exercise_number_seq`, cinq fonctions,
dix déclencheurs ; équivalent `scripts/migrate_all_v2_pertes_l2a.sh`). Relu par un relecteur indépendant avant la mise en ligne.
Textes `translations/pertes.{fr,en}.yaml` (`pieces.*`). Toutes les routes : `/api/gestion/pertes`, administrateur, garde de la gestion
puis du module, `?locale=fr|en`, erreurs `{ error, code, message, … }`. **Aucune n'écrit dans le stock, les ventes ni le catalogue.**

**Fiche d'une pièce** (rendue partout) : `{ id, number, status (created | retired), statusLabel, zone (null en L2), exercise,
variantId, productId, variantMissing, name, variantLabel, barcode, barcodeOf (variant | product | null), symbology, tags [puce],
tagged, commandeId, retiredReason, retiredReasonLabel, createdAt, retiredAt }`. Le code-barres est lu sur la variante (celui de
l'article quand elle est sa seule variante) ; `variantMissing: true` si la variante n'est plus au catalogue (la pièce reste).
**Puce** : `{ id, identifier, technology (uhf | nfc | simulated), technologyLabel, status (known | associated | retired),
statusLabel, pieceId, exercise, associatedAt, retiredAt, retiredReason, retiredReasonLabel }`.

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `GET /pieces` | `?variantId&productId&status&tagged=0\|1&page&limit` (50, 100 au plus) | 200 `[fiche]`, les plus récentes d'abord ; en-têtes `X-Total-Count`, `X-Page`, `X-Limit` | 422 `VALIDATION_FAILED` |
| `POST /pieces` | `{ variantId \| barcode, count (1 à 500, 1 par défaut), commandeId? }` | 201 `{ pieces [fiche], count }` | 422 (`count: OUT_OF_RANGE`, `target: ONE_TARGET_REQUIRED`…) ; 404 `VARIANT_NOT_FOUND`, `BARCODE_NOT_FOUND`, `COMMANDE_NOT_FOUND` ; 409 `BARCODE_AMBIGUOUS { productId, variantIds }`, `NO_VARIANT { productId }` |
| `GET /pieces/{id}` | — | 200 fiche + `events [{ id, type, label, at, userId, tagId, data }]` | 404 `PIECE_NOT_FOUND` |
| `POST /pieces/{id}/retirer` | `{ reason (damaged \| lost \| sold \| error), confirm: true }` | 200 `{ changed, piece }` ; ses puces actives sont retirées avec elle (`piece_retired`) | 409 `CONFIRMATION_REQUIRED` ; 422 ; 404 |
| `POST /puces/associer` | `{ pieceId \| variantId \| barcode, identifier, technology (uhf \| nfc) }` | 200 `{ created, associated, piece, tag }` | 422 `TAG_INVALID { technology }`, `VALIDATION_FAILED` ; 409 `TAG_ALREADY_ASSOCIATED { holder }`, `TAG_RETIRED`, `TAG_IS_BARCODE { holder }`, `PIECE_RETIRED`, `PIECE_TAG_LIMIT { limit: 4 }`, `BARCODE_AMBIGUOUS`, `NO_VARIANT` ; 404 `PIECE_NOT_FOUND`, `VARIANT_NOT_FOUND`, `BARCODE_NOT_FOUND` |
| `GET /puces/recherche?identifier=` | — | 200 `{ identifier (forme canonique), found, refusal (TAG_INVALID \| null), tag, piece }` | 422 `identifier: REQUIRED` |
| `POST /puces/{id}/retirer` | `{ reason (detached \| destroyed \| lost \| error), confirm: true }` | 200 `{ changed, tag, piece }` | 409 `CONFIRMATION_REQUIRED` ; 422 ; 404 `TAG_NOT_FOUND` |
| `GET /pieces/synthese` | `?variantId&productId&page&limit` | 200 `{ rows [{ variantId, productId, …catalogue, stock, pieces { active, retired, tagged, untagged }, gap }], total, page, limit, totals { variants, pieces, retired, tagged, untagged } }` | 422 |

- **Associer, LE geste** : avec `pieceId`, la puce se pose sur cette pièce ; avec `variantId` ou `barcode` (le code-barres lu), une
  pièce est créée pour la variante puis reçoit la puce (`created: true`). **Rejouer le même geste ne crée rien** : la puce déjà
  posée sur une pièce de cette variante rend cette pièce (`created: false`, `associated: false`). La même puce pour une autre pièce
  ou une autre variante : 409 `TAG_ALREADY_ASSOCIATED`, `holder { id, number, variantId, productId, name, variantLabel }`. Une pièce
  porte quatre puces au plus ; à partir de la deuxième, un fait `PAIR_SET`.
- **Identifiant** (`LossTagIdentifier`) : la lecture est nettoyée (espaces, `:`, `-`, `.`, préfixe `0x`, majuscules) ; NFC : 8, 14,
  16 ou 20 caractères hexadécimaux ; UHF : 16 à 64, par multiples de 4. La lecture brute est gardée (`loss_tag.raw`). Une lecture qui
  est le code-barres d'un article est refusée (`TAG_IS_BARCODE` : lecteur en clavier dans le mauvais champ ; comparée telle qu'un
  code-barres l'écrirait et sous sa forme nettoyée). Une puce simulée n'est jamais une lecture valide sur une vraie route, recherche
  comprise (`refusal: TAG_INVALID`).
- **Deux envois au même instant** : la base tranche (index unique de l'identifiant). Le second envoi est annulé en entier puis rejoué
  une fois : le même geste envoyé deux fois rend le rejeu (la pièce du premier, `created: false`), une autre cible rend
  `TAG_ALREADY_ASSOCIATED` avec ce qui porte la puce. Poser une puce prend la pièce sous verrou : une pièce qu'on retire au même
  instant ne reçoit pas de puce.
- **Retirer** : jamais d'un geste distrait (`confirm: true`, sinon 409) ; retirer deux fois ne fait rien (`changed: false`). Une puce
  retirée ne se repose sur aucune pièce (`TAG_RETIRED`), pas même la sienne ; une pièce retirée ne reçoit plus de puce.
- **Faits** (`loss_piece_event`, en ajout seul) : `PIECE_CREATED`, `TAG_ASSOCIATED`, `PAIR_SET`, `TAG_RETIRED`, `PIECE_RETIRED`, dans la
  transaction de la ligne qu'ils changent, avec le compte de l'administrateur ; nombres, codes et identifiants seulement.
- **Ce que la base garantit seule** (déclencheurs de la migration) : identifiant unique et en forme canonique ; une puce associée ne
  change jamais de pièce ; l'état d'une puce n'avance que dans un sens (connue, associée, retirée) ; une puce ou une pièce retirée
  ne bouge plus (ni l'état, ni le motif, ni la date) ; identité figée (numéro, variante, article, commande d'origine, lecture brute,
  technologie, auteur, dates de création et de pose, marque d'exercice) ; puce posée seulement sur une pièce du même genre et non
  retirée ; faits non modifiables, et du même genre que la pièce et la puce qu'ils désignent ; aucune suppression ni vidage, sauf
  une ligne d'exercice par la porte `loss.purge = 'exercise'`, dans l'ordre faits, puces, pièces.
- **Synthèse** : par variante suivie, à côté du stock, pour information ; `gap` = stock − pièces en service ; filtres `variantId`,
  `productId` ; `total` et `totals` portent sur tout ce que le filtre retient, pas sur la page. Vraies pièces seulement.
- **Listes** : `X-Total-Count` et `total` ne dépendent pas de la page demandée (une page au-delà de la dernière est vide).
- **Limites connues** : `POST /pieces` n'est pas rejouable (deux envois créent deux séries ; une série créée par erreur se retire
  pièce par pièce) ; rien n'empêche encore de poser comme code-barres d'un article l'identifiant d'une puce connue (sens inverse de
  `TAG_IS_BARCODE`, à traiter dans un lot du catalogue) ; un identifiant de route au-delà d'un entier rend 404 par la garde commune.

**Mode exercice** (ouvert sans le droit du module, comme le lot EX-1 ; routes nommées une à une dans
`LossPreventionModuleSubscriber::OPEN_ROUTES`) : les mêmes gestes sur des pièces d'exercice et des puces simulées.

| Route | Rôle |
|---|---|
| `GET /exercices/pieces` | `{ pieces, count, limit: 30, remaining, createLimit: 10, tagLimit: 60, daily { limit: 30, remaining, resetAt } }` |
| `POST /exercices/pieces` `{ variantId \| barcode, count (1 à 10) }` | 201 `{ pieces, count }` ; 409 `EXERCISE_LIMIT_REACHED { limit }`, `EXERCISE_DAILY_LIMIT { limit, resetAt }` |
| `POST /exercices/puces/associer` `{ pieceId \| variantId \| barcode, identifier? }` | « Simuler une lecture » : sans `identifier`, le serveur fabrique la puce (`SIM-` + 12 caractères) |
| `GET /exercices/puces/recherche?identifier=`, `GET /exercices/pieces/{id}` | comme les vraies routes |
| `POST /exercices/puces/{id}/retirer`, `POST /exercices/pieces/{id}/retirer` | comme les vraies routes |
| `DELETE /exercices/pieces` | `{ deleted }` : toutes les pièces d'exercice, leurs puces et leurs faits |

Les deux mondes ne se croisent sur aucune route (une vraie pièce est 404 pour les routes d'exercice, et l'inverse ; une puce simulée
n'est pas une lecture valide sur une vraie route, ni une vraie lecture en exercice), et **rien d'exercice n'entre dans un chiffre
réel** (liste, synthèse). Plafonds : 30 pièces d'exercice présentes, 10 par appel, 60 puces simulées présentes (retirées comprises :
poser puis retirer en boucle ne remplit pas la base ; 409 `EXERCISE_LIMIT_REACHED { limit: 60 }`), 30 APPELS de création par jour du
magasin (comptés au journal du module, lignes `EXERCISE_PIECES_CREATED` : effacer ne rend aucun appel). Les pièces d'exercice ont
leur propre suite de numéros : un exercice ne creuse aucun trou dans la numérotation réelle. Toute écriture d'exercice prend le
verrou des exercices : un effacement en cours ne laisse ni puce ni fait sans leur pièce. L'exercice lit de vraies variantes du
catalogue (lecture seule).

Tests : `tests/Functional/LossPrevention/LossPreventionPiecesTest.php` (la base seule, à une et à deux connexions ; les routes ;
l'exercice ; le même geste envoyé deux fois au même instant, par deux processus ; l'attente des verrous ; le rejeu et le retour
arrière de la migration ; les règles de l'identifiant).

### 13.10 Lot L2-b codé : étiquettes des pièces, assistant d'installation, gamme de matériel (15/11/2026)

Code : `Controller/LossInstallationController`, `UseCase/LossInstallationUseCase`, `Services/LossPieceLabelService`,
`Services/LossInstallationService`, `Services/LossHardwareCatalog` ; l'imprimeur d'étiquettes du catalogue
(`Services/BarcodeService/BarcodeLabelPrinter`) gagne le format rouleau et une ligne de plus par étiquette. **Aucune migration.**
Textes `translations/pertes.{fr,en}.yaml` (`piece_labels.*`, `installation.*`, `hardware.*`). Codes d'écran : `pertes.pieces`,
`pertes.installation` (guides `translations/ecrans.*`, console, liens des courriels). Mêmes droits et mêmes gardes que le § 13.9.

| Route | Corps | Réponse | Refus |
|---|---|---|---|
| `POST /pieces/etiquettes/pdf` | `{ pieceIds [entiers distincts], format? (a4-50x30 \| roll-50x30), showPrice? }` | 200 `application/pdf`, en-têtes `X-Labels-Count`, `X-Labels-Format` | 422 `VALIDATION_FAILED`, `LABELS_LIMIT_EXCEEDED { max, requested }`, `PIECES_NOT_FOUND { pieceIds }` ; 409 `PIECE_RETIRED { pieceIds }`, `BARCODE_MISSING { pieceIds, variantIds }` |
| `GET /installation` | — | 200 `{ steps [{ code, title, text, image, status (done \| current \| todo), verify { kind (data \| none), label } }], progress { done, total }, complete, exercise, screen }` | — |
| `POST /installation/etapes/{code}/verifier` | — | 200 `{ code, verified, hint }` | 404 `STEP_NOT_FOUND` |
| `POST /installation/puce-test` | `{ identifier, technology (uhf \| nfc) }` | 200 `{ ok, identifier, technology, refusal (TAG_INVALID \| TAG_IS_BARCODE \| null), hint, tag, installation }` | 422 `VALIDATION_FAILED` |
| `POST /installation/etiquette-test` | `{ format? }` | 200 `application/pdf`, `X-Labels-Format` | 422 `format: INVALID_VALUE` |
| `GET /materiel` | — | 200 `{ needs [{ code (read \| print \| attach), label, levels [{ level (pro \| standard \| diy), label, technology, what, allows, limits }] }], levels [{ code, label, summary, soldWithHardware }], note, screen }` | — |

- **Étiquettes des pièces** : une étiquette par pièce, dans l'ordre demandé : nom de l'article, variante, **code-barres de la
  variante** (celui que la caisse lit ; règle du § 13.9), le numéro de la pièce (« Pièce n° 1042 »), le prix du catalogue hors
  promotion si `showPrice` (un prix à zéro vaut « pas de prix »). `a4-50x30` : planche A4, 500 étiquettes au plus ; `roll-50x30` :
  une page de 50 × 30 mm par étiquette, pour une imprimante thermique à rouleau, 200 au plus (mesuré le 15/11/2026 : 200 étiquettes
  en 4 s, 500 en 16 s). Une pièce retirée n'a plus d'étiquette ; une pièce dont la variante n'a pas de code est refusée. Une
  impression est une lecture : rien n'est écrit. Les PDF d'étiquettes du catalogue (`docs/codes-barres.md` § 7 et § 8) restent en
  planche A4 ; leur donner le format rouleau est un ajout d'un paramètre, non fait dans ce lot.
- **Assistant d'installation** : quatre étapes en L2, dans l'ordre : `hardware` (choisir son matériel : étape d'information),
  `test_label` (étiquette d'essai), `test_tag` (lire une puce d'essai), `first_piece` (poser la puce sur une première pièce).
  **L'avancement se calcule à partir des données**, jamais d'une case cochée ; rien n'est stocké pour l'assistant, et le lire ou
  « Vérifier » n'écrit rien. Preuves : `test_label`, un fait `TEST_LABEL_PRINTED` ; `test_tag`, un fait `TEST_TAG_READ` ;
  `first_piece`, une pièce en service qui porte une puce posée (lue dans `loss_tag` : une puce retirée ne prouve plus rien).
  **Une étape est faite si sa preuve existe ou si une étape qui la suit est faite** : un site qui pose déjà ses puces n'a pas à
  refaire les essais. La première étape pas faite est `current`. `image` : une clé d'illustration (`pertes/installation/<code>`) ;
  des dessins tant que le matériel n'est pas choisi. Les étapes du boîtier (L3) et des antennes (L4) s'ajouteront à la même suite.
  Un code d'étape hors de la forme attendue (lettres minuscules et `_`) rend le 404 du routeur.
- **Lire une puce d'essai** : si la lecture a la forme d'une puce, l'étape est prouvée par un fait `TEST_TAG_READ` (technologie et
  identifiant lus) ; la réponse rend l'assistant à jour. **La puce n'est pas enregistrée** : elle ne le sera qu'en étant posée sur une
  pièce (`POST /puces/associer`), avec la technologie donnée ce jour-là. Une lecture d'essai ne fige donc rien (les longueurs de 16
  et 20 caractères sont valides en NFC comme en UHF). L'étape prouve que le LECTEUR marche : si la puce est déjà posée ou retirée, la
  lecture marche (`ok: true`, `tag` rendu) et `hint` dit d'en prendre une libre. Sinon rien n'est écrit, et `hint` dit quoi essayer :
  code-barres d'un article lu à la place de la puce (`TAG_IS_BARCODE`, que la lecture ait ou non la forme d'une puce), lecteur qui
  rend des chiffres seulement, autre chose que de l'hexadécimal, mauvaise longueur pour la technologie.
- **Étiquette d'essai** : une étiquette au format demandé, avec un code qui ne peut désigner aucun article (`IZ-ESSAI-ETIQUETTE` : le
  préfixe `IZ-` est réservé aux documents de la caisse, le catalogue le refuse). Le serveur ne voit pas l'imprimante : le fait
  `TEST_LABEL_PRINTED`, écrit après le rendu, dit seulement que l'étiquette a été produite.
- **Une preuve suffit** : chacun des deux gestes d'essai n'écrit son fait que la première fois, dans le monde réel comme en exercice.
  Ni un double appui ni des essais répétés ne remplissent une table où rien ne s'efface.
- **Gamme de matériel** : trois besoins (lire une puce, imprimer l'étiquette, poser la puce), trois niveaux chacun (pro, standard,
  bricoleur : code `diy`), avec ce qu'il faut, ce que ça permet, ses limites, et la technologie de puce conseillée pour lire. Des
  textes, sans prix ni vente (lot L6). `soldWithHardware: false` pour le niveau bricoleur : il se vend sans matériel (décision du
  propriétaire du 15/11/2026). Le modèle ne dépend pas du niveau.
- **Mode exercice** (ouvert sans le droit du module, routes nommées une à une dans `OPEN_ROUTES`) : `POST /exercices/pieces/etiquettes/pdf`,
  `GET /exercices/installation`, `POST /exercices/installation/etapes/{code}/verifier`, `POST /exercices/installation/puce-test`
  (`identifier` facultatif : puce simulée), `POST /exercices/installation/etiquette-test`, `GET /exercices/materiel`. L'assistant
  d'exercice suit les données d'exercice seulement : il n'avance jamais l'installation réelle, et l'effacement des pièces d'exercice
  (`DELETE /exercices/pieces`, à proposer même quand il n'y a aucune pièce) le remet à zéro, essais compris. Les essais d'exercice
  prennent le verrou des exercices.
- **Non fait, à savoir** : refuser de poser comme code-barres d'un article l'identifiant d'une puce connue (cela toucherait le service
  des codes-barres du catalogue ; la garde `replace` du lot CB-3 et les champs distincts des écrans couvrent le geste distrait).

Relu par un relecteur indépendant avant la mise en ligne. L'état `known` d'une puce (§ 13.2) reste réservé : aucune route ne le
crée dans ce lot.

Tests : `tests/Functional/LossPrevention/LossPreventionInstallationTest.php` (étiquettes dans les deux formats, une page par étiquette
en rouleau, à la taille de l'étiquette ; l'assistant depuis zéro en exercice, puis dans le monde réel par ses propres données ; une
preuve écrite une seule fois ; l'attente du verrou des exercices ; la gamme).

**Exemples de réponse** (lots L2-a et L2-b) : `tests/Fixtures/pertes/pieces/`, un fichier JSON par route et par refus (création,
association créée / rejouée / deuxième puce, liste, fiche avec ses faits, recherche trouvée / inconnue / refusée, synthèse, retraits,
assistant, lecture d'essai, gamme, état et parcours d'exercice, refus avec leurs détails), pour les tests de contrat du web et du
mobile. `LossPreventionPiecesExamplesTest` rejoue le parcours et échoue si un exemple n'a plus la forme de la réponse du serveur ;
son en-tête dit comment les régénérer. Les PDF ne sont pas des exemples.

## 14. Ajouts du propriétaire pour les plans des lots L3, L4 et L5 (15/11/2026, SANS CODE)

Relayés par le chef de projet le 15/11/2026. Rien de ce paragraphe n'est codé ni planifié en détail : ce sont des décisions à
reprendre telles quelles dans les plans de L3 (boîtier et comptoir), L4 (antennes, comptage) et L5 (avis de l'IA, alertes), chaque
plan étant validé par le propriétaire avant son code.

1. **Antenne à l'entrée de la zone des toilettes** : à l'ENTRÉE de la zone, jamais dedans ; sans caméra ni son ; avec une affichette.
   Elle a son scénario : une pièce lue en entrant dans cette zone, puis plus jamais lue ailleurs, donne le constat « puce disparue
   après la zone sensible ».
2. **Caméra au dépôt** : elle filme le stock et les accès ; jamais un poste en continu, une salle de pause ni un vestiaire. Elle suit
   la notice aux salariés, les interrupteurs par pays et la consultation des représentants du personnel en France. On ne garde que
   les extraits liés aux incidents, sans son.
3. **Nouveaux scénarios, réels et d'exercice** : une sortie du dépôt sans transfert ; la porte arrière du dépôt ; une sortie du dépôt
   hors des heures d'ouverture.
4. **La caméra ne déclenche jamais d'alerte seule** : la puce donne l'alerte. Le boîtier joint l'extrait des dernières minutes de la
   zone où la puce a été lue en dernier. En L5, l'IA n'analyse que cet extrait et donne un avis en pourcentage (décision 11 du § 12 :
   le pourcentage est calculé par le serveur).
5. **Pour L3** :
   - le lecteur de comptoir est un tapis à courte portée branché au boîtier, et la plateforme relie la vente aux puces lues au
     comptoir ;
   - une route rend, avant le paiement, les articles suivis du panier qui n'ont pas été lus sur le tapis de ce poste ; le lien entre
     un tapis et un poste de caisse se fixe dans l'assistant d'installation (§ 13.10 : ses étapes s'ajoutent à la même suite) ;
   - au portique, le rattrapage accepte une pièce de la variante quand une vente de cette variante n'a pas encore de pièce, une seule
     par article vendu ;
   - le boîtier tourne sous Linux Debian, avec le logiciel en conteneur multi-architecture installé par un paquet `.deb`, et il
     contacte la plateforme sans jamais ouvrir de port (§ 2, « clé de boîtier »).
6. **Avant le premier client**, l'avocat devra vérifier la zone des toilettes et la caméra du dépôt, avec la lecture d'une puce sur
   un article vendu (§ 13) et la décision 15 du § 12.

Ces ajouts restent dans les limites du § 1 : jamais de reconnaissance des visages, de son, de caméra en cabine ni d'analyse de gestes
par vidéo ; aucune suite automatique, la décision reste humaine.

## 15. Niveau pro avec imprimante à puce (demande du propriétaire, 15/11/2026, SANS CODE) : rangé dans le lot L3

L'imprimante à puce imprime l'étiquette et écrit la puce d'un même geste, et la pièce est associée à l'impression, sans geste de
plus. **Lot choisi : L3**, comme sous-lot placé après la clé de boîtier et le canal des faits du boîtier : sans boîtier, rien dans
la boutique ne peut recevoir un travail de la plateforme ni piloter une imprimante (la plateforme ne joint jamais la boutique : c'est
le boîtier qui la contacte, § 14, point 5). Le plan détaillé sera écrit avec celui de L3 et validé par le propriétaire avant son code.

- **Chemin** : (1) le gérant demande les étiquettes de pièces (créées à la réception ou déjà là, § 13.9) avec le format « imprimante à
  puce » ; (2) la plateforme enregistre un **travail d'impression** : une ligne par pièce, avec ce qu'il faut imprimer et l'identifiant
  à écrire dans la puce ; (3) le boîtier, à son prochain contact (il vient chercher ses travaux avec son jeton signé, il n'ouvre aucun
  port), reçoit le travail ; (4) il pilote l'imprimante, branchée sur lui en USB ou sur le réseau de la boutique, dans le langage de
  l'imprimante ; (5) pour chaque étiquette, l'imprimante imprime, écrit la puce puis la RELIT ; (6) le boîtier renvoie un fait par
  étiquette, dans un lot rejouable (clé d'idempotence par ligne du travail : `loss_piece_event.event_key` existe déjà) ; (7) la
  plateforme associe la puce à la pièce pour chaque écriture vérifiée, et marque la ligne faite. L'application suit le travail
  (en attente, en cours, fait, lignes en échec).
- **Format d'encodage** (proposition, à arrêter à l'essai du matériel) : un identifiant de 96 bits fabriqué par la plateforme, écrit
  dans la mémoire d'identification de la puce : une marque fixe de la plateforme, le site, le numéro de la pièce, une clé de
  contrôle. Unique sur toute la plateforme, donc sur le site (l'index unique du § 13.9 s'applique tel quel) ; lisible ensuite par
  n'importe quel lecteur UHF, il suit la forme canonique déjà acceptée (24 caractères hexadécimaux). **Pas de code de fabricant ni
  de numérotation GS1** : la boutique n'a pas de préfixe d'entreprise GS1, et écrire le code d'un fabricant suivi de nos numéros
  de série mettrait nos pièces dans SA numérotation ; la puce se déclare hors de cette numérotation. Le code-barres imprimé sur
  l'étiquette reste celui de la variante (ce que la caisse lit).
- **Verrouillage de la puce** (décision du propriétaire, 15/11/2026) : après l'écriture, la puce est verrouillée **par mot de passe,
  jamais par un verrou définitif**. Un mot de passe d'accès **par site**, rangé chiffré dans la plateforme, que seul le boîtier
  utilise, à l'impression ; il n'est jamais imprimé, jamais affiché, et n'apparaît dans aucun journal (ni de la plateforme, ni du
  boîtier, ni dans un fait). La **lecture reste ouverte à tous** : les antennes et les lecteurs doivent lire sans rien savoir. La
  **réécriture n'est possible qu'avec le mot de passe**, ce qui permet de corriger une puce mal écrite. À dire dans le plan : ce
  mot de passe est une protection légère (celle que la puce offre), qui arrête la réécriture ordinaire.
- **Pas de contrôle des copies** (décision du propriétaire, 15/11/2026) : on n'enregistre pas le numéro d'usine des puces (TID) et
  il n'y a pas de constat « puce copiée ». Le risque est trop faible pour des articles à 200 $ au plus. Seul le verrou par mot de
  passe reste.
- **Si l'écriture échoue** : la puce n'est jamais enregistrée sans relecture conforme. L'imprimante annule l'étiquette ratée (elle la
  marque comme nulle) et réessaie sur l'étiquette suivante, un petit nombre de fois ; au-delà, la ligne du travail passe en échec
  avec son motif (puce illisible, écriture refusée, imprimante arrêtée, plus d'étiquettes) et **la pièce reste sans puce** : on la
  réimprime, ou on lui pose une puce à la main par le geste ordinaire. Un travail rejoué par le boîtier ne pose pas deux puces sur
  une pièce (clé d'idempotence), et une pièce qui a déjà sa puce n'est pas réimprimée sans confirmation. Boîtier ou imprimante
  injoignable : le travail attend, l'application le dit ; rien n'est perdu ni associé à tort.
- **Modèles visés** (à confirmer chez le fournisseur au moment de l'achat : références, disponibilité, prix) : en premier, une
  imprimante de bureau thermique à encodeur UHF qui parle le langage ZPL, la famille la plus répandue en boutique (Zebra ZD621R en
  4 pouces, ZD611R en 2 pouces) ; ensuite, selon la demande, les imprimantes à encodeur UHF d'autres marques qui acceptent le même
  langage. Critères : encodage et relecture de puces UHF, étiquettes de 50 × 30 mm à puce intégrée, pilotage en USB ou par le réseau
  local depuis le boîtier Debian (§ 14, point 5). Aucun de ces modèles n'a été essayé : la campagne d'essai sur du vrai matériel
  (§ 13.7, risque 7) vaut aussi pour l'imprimante.
- **Dans l'application dès aujourd'hui** : la gamme (`GET /materiel`, § 13.10) décrit déjà ce niveau pour « imprimer l'étiquette » et
  « poser la puce », en disant que l'écriture de la puce n'est pas encore proposée ; le texte changera avec ce sous-lot. Les niveaux
  standard et bricoleur ne changent pas : puces autocollantes numérotées en usine, lues une fois.

