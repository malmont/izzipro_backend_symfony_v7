# Prévention des pertes : constats tirés de la caisse, preuves datées, décision du gérant

Fiche du module « Prévention des pertes » (vol en boutique physique), section de l'application de gestion activable avec sa propre clé.
Décisions du propriétaire du 14/11/2026 (15 décisions, § 12). **Lot L1-a codé le 14/11/2026** : caisse seule, sans matériel, vague 1 des
règles. **Lot L1-b codé le 14/11/2026** : vague 2 des règles, aperçu d'un seuil avant de l'appliquer, codes d'écran et guides, vignette de
l'accueil, étapes du cheminement, courriel de résumé du jour. **Lot EX-1 codé le 14/11/2026** : mode exercice (constats fictifs que le
gérant lance et efface, ouverts sans le droit du module et avant la notice ; § 8). **Lot L3-a codé le 15/11/2026** : boîtier de la boutique (clé, inscription,
canal des faits, boîtier simulé ; § 16.13). Code : `src/LossPrevention/` (module autonome, mêmes couches que le reste). Web : session « securite de la boutique » ; mobile :
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
- **Clé de boîtier** (lot L3-a, codé le 15/11/2026, § 16.13) : le boîtier de la boutique s'inscrit avec une clé `IZ-BOX-…` (une clé par
  boîtier, montrée une fois, empreinte seule en base, dans la table du module et non dans les clés d'activation de la plateforme) et
  reçoit un jeton signé qu'il envoie à chaque appel (`X-Loss-Box`) ; révocable, sans aucun droit de lecture. Ses routes sont sous
  `/api/pertes/boitier`, hors de `/api/gestion` : sans rôle dans `access_control`, fermées par `LossBoxGuardSubscriber`.
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
| `UNREAD_SOLD` (lot L3-b2b, § 16.19) | 3 | Sur un POSTE de caisse et une JOURNÉE du magasin, `minCount` articles suivis vendus sans lecture (ni sur le tapis, ni rattrapés à la sortie, fenêtre de rattrapage passée), qui font au moins `minShare` % des articles suivis vendus ce jour-là sur ce poste. Jugée à chaque clôture du poste, pour le jour et pour la dernière journée d'activité d'avant. **Jamais par personne : personne n'est listé « en poste »** ; pas de montant en jeu | `minCount` 5 (1 à 500), `minShare` 20 % (1 à 100) | `cash_register` ; `UNREAD_SOLD:register:<poste>:day:<jour>` |

- Date d'une annulation : `order.canceled_at` (posé par `CancelOrderUseCase` pour tous les chemins), sinon l'heure de l'entrée REFUND pour
  une annulation d'avant PAYS-1b ; un retour (commande de type 2) n'est pas une annulation.
- Réservées (les données existent déjà au journal) : `CLOSE_WITHOUT_COUNT`, `REPRINT_PATTERN`, `GIFT_CARD_PATTERN`, `CUSTOM_ITEM_PATTERN`,
  `OFFLINE_FORCED`, paiements par carte commencés puis annulés, `OFF_HOURS` (aucun réglage d'heures d'ouverture du magasin n'existe).
- Garde-fou petite équipe (pour les règles en proportion de la vague 2) : au moins 20 ventes sur la période, sinon la règle compte en nombres.

## 5. Constat (`loss_finding`)

`{ id, number, rule { code, label }, personPattern, status, statusLabel, summary, storeDay, cashRegister { id, name } | null, amountAtStake,
currency, isNew, newEvidenceSinceDecision, backfill, updatedAt }` (ligne de la liste) ; la fiche ajoute `from`, `to`, `sessionId`,
`subject { type: z_report | session | order | return | terminal | user_window | cash_register, id, name?, label? }` (`cash_register` : lot L3-b2b, avec le nom du poste et le libellé « Poste de caisse »), `onDuty [{ id, name, role: seller | closer | approver |
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

Preuve : `{ id, kind: z_report | drawer | order | credit_note | return | discount | price_change | approval | sale_units (lot L3-b2b, § 16.19 : des nombres par poste et par jour, son renvoi `ref.type` vaut `closing`), at, atLabel, title, lines [{ label, value }], ref { type, id, sequence, hash },
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
  `refund_cash_for_card`, `discount_vague`, `price_override`, `approval_refused`, `unread_sold` (lot L3-b2b : un poste, une journée,
  personne n'est listé), et trois variantes par personne (`cash_shortage_series`,
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


## 16. Lot L3 : boîtier, comptoir et imprimante à puce (plan validé par le propriétaire le 15/11/2026 ; L3-a codé)

**Plan validé par le propriétaire le 15/11/2026, avec les onze propositions du point 16.12** (dont la suppression automatique des
extraits de la caméra après 30 jours). Ordre : L3-a, L3-b, L3-c ; L3-d seulement après l'avis de l'avocat. Le dépôt du logiciel du
boîtier se crée au moment du code, en local seulement (aucun dépôt distant sans décision du propriétaire). **Le lot L3-a est codé** :
son contrat exact est au point 16.13, qui fait foi là où il précise le plan.

Ce plan reprend tout le § 14 (point 5 en entier, points 2, 4 et 6 pour la caméra) et tout le § 15. Rien n'est codé. Le dépôt du
logiciel du boîtier n'existe pas : il sera créé au moment du code, sur décision du propriétaire. Lot à risque d'un bout à l'autre
(une entrée nouvelle sur la plateforme, la caisse, un secret, de la vidéo, du matériel) : relecteur neuf et mutations à chaque
sous-lot.

### 16.1 Ce que L3 apporte, et ce qu'il n'apporte pas

- **Apporte** : un **boîtier** dans la boutique, qui envoie des faits à la plateforme ; un **tapis de comptoir** qui dit QUELLE pièce
  est vendue (L2 ne le savait pas, § 13.1) ; pour la caisse, la liste des **articles suivis du panier qui n'ont pas été lus** avant
  le paiement ; le **rattrapage** d'une pièce lue à la sortie après sa vente ; l'**imprimante à puce** du niveau pro (§ 15) ; la
  **caméra du comptoir**, qui ne fournit que des extraits ; un **simulateur** et une **machine de test**, pour tout jouer sans matériel.
- **N'apporte pas** : les antennes de zone, le portique réel, le comptage et le bilan du soir (L4) ; le constat « sortie sans
  vente » (L4, il lui faut le portique) ; l'avis de l'IA et les alertes en caisse (L5) ; la vente du matériel (L6). **Aucune
  écriture de vente, de stock ni de catalogue** (§ 1) : le module continue de n'écrire que dans ses tables.
- **Deux règles du § 1 guident tout le plan** : aucun code de caisse n'appelle le module pendant une vente ; la décision reste
  humaine (la caisse n'est jamais bloquée par le module).

### 16.2 Le boîtier : Debian, paquet, clé, inscription

- **Machine** : un petit ordinateur sous Linux **Debian stable**, en `amd64` ou en `arm64`. Le logiciel est un **conteneur
  multi-architecture** installé par un **paquet `.deb`** : le paquet pose le service (`systemd`), ses réglages (`/etc`), sa file de
  faits sur disque (`/var/lib`), le pare-feu qui **refuse toute connexion entrante**, et l'image du conteneur à une version exacte
  (jamais « la dernière »). Mise à jour = nouvelle version du paquet, servie par un dépôt de paquets signé de la plateforme.
- **Jamais de port ouvert** : le boîtier appelle la plateforme en HTTPS sortant, à l'adresse du site ; la plateforme ne le joint
  jamais. Pas d'accès à distance (ni SSH ni tunnel) en L3. Les appareils (tapis, imprimante, caméra) sont branchés sur lui en USB
  ou sur le réseau local de la boutique.
- **Clé de boîtier** (§ 2) : une application de plus dans les clés d'activation (`tenant_app_keys`, forme `IZ-BOX-…`), créée par
  l'administrateur dans l'écran du module (« Ajouter un boîtier » : nom, endroit) et montrée une seule fois, avec son code QR.
  **Une clé = un boîtier** (à la différence d'une clé de caisse, qui sert à plusieurs tablettes).
- **Inscription** : sur le boîtier, la commande d'installation demande la clé (tapée, ou lue par un lecteur de codes-barres) et
  appelle la route d'inscription, publique et limitée en débit comme `POST /api/app/activate`. La plateforme lie la clé à CETTE
  machine (empreinte de la machine, version, architecture) et rend un **jeton signé**. Une seconde machine avec la même clé est
  refusée (`BOX_KEY_ALREADY_ENROLLED`) tant que l'administrateur n'a pas remis la clé à zéro (boîtier remplacé).
- **Jeton** : envoyé à chaque appel ; durée courte, renouvelé au contact ; révoqué d'un geste par l'administrateur (403
  `BOX_REVOKED` aussitôt). **Le boîtier n'est pas un utilisateur** : son jeton ne donne que quatre droits, sur son propre site :
  envoyer des faits, lire SES réglages (appareils, zones), prendre SES travaux (impression, extrait), déposer un extrait. **Aucune
  lecture** des ventes, des clients, du catalogue ni des constats.
- **Routes du boîtier** : hors de `/api/gestion` (réservé à l'administrateur), sous un préfixe à elles (`/api/pertes/boitier/…`),
  derrière un contrôle d'accès propre (rôle technique `ROLE_LOSS_BOX`, que rien d'autre ne porte), limitées en débit par site, par
  adresse et par boîtier, et fermées quand le droit du module est retiré (le boîtier garde alors sa file, bornée, et réessaie).
  Ces fichiers sont partagés (clés d'activation, `security.yaml`) : à écrire avec « appli degestion backend ».
- **État** : `loss_box` (nom, endroit, clé, empreinte, version, dernier contact, écart d'horloge, état) et `loss_device` (un appareil
  déclaré par le boîtier : genre `counter_mat | reader | printer | camera`, numéro de série, zone, poste de caisse pour un tapis,
  dernier signe de vie). L'application liste les boîtiers et leurs appareils, « en ligne » ou « silencieux depuis… ».

### 16.3 Le canal des faits

- **Un appel** : le boîtier envoie des **lots rejouables** de 1 à 200 faits `{ key, type, at, seq, device, zone, data }`. `key` est
  la clé d'idempotence du fait (`loss_piece_event.event_key` existe déjà) : un fait déjà reçu répond « déjà connu », sans doublon.
  La réponse dit, fait par fait, `accepted`, `duplicate` ou `refused { code }` ; un fait refusé (puce inconnue, forme invalide,
  appareil non déclaré) n'est pas renvoyé, et il ne fait jamais échouer le lot.
- **Faits de L3** (les noms du § 11 sont gardés) : sur les pièces, `COUNTER_SEEN` et `COUNTER_LEFT` (une puce arrive sur un tapis,
  le quitte), `ZONE_SEEN` (lecture en zone ; en L3 la seule zone qui compte est `exit`), `SPOT_CHECK` (lecteur à main),
  `TAG_WRITTEN` et `TAG_WRITE_FAILED` (imprimante) ; sur les appareils, `SOURCE_DOWN` et `SOURCE_UP`, `CLIP_READY`. Les faits de
  pièces vont dans `loss_piece_event` ; ceux des appareils dans une table inaltérable à eux (`loss_box_event`).
- **Le signe de vie n'est pas un fait gardé** : toutes les minutes le boîtier dit « je suis là » avec la liste de ses appareils ; la
  plateforme met à jour `loss_box` et n'écrit un fait que pour un CHANGEMENT (boîtier ou appareil devenu silencieux, revenu).
  Sinon chaque boîtier écrirait 1 440 lignes inaltérables par jour pour ne rien dire.
- **Heure** : chaque fait garde l'heure du boîtier et l'heure de réception. L'écart d'horloge est mesuré à chaque signe de vie ;
  au-delà d'une minute, l'assistant le dit et les faits portent un doute sur l'heure (ils ne servent pas à rattacher une vente).
- **Coupure du réseau** : le boîtier range ses faits sur disque et les envoie dans l'ordre au retour ; la file est bornée (7 jours),
  et un trou est dit par un fait (`SOURCE_DOWN` avec la période). Le tapis ne dit pas tout ce qu'il voit : une arrivée, un départ.
- **Exercice** : un boîtier d'exercice et ses faits portent `exercise = true`, comme les puces simulées (§ 13.4) : jamais dans un
  chiffre réel, effacés d'un geste. Un vrai boîtier refuse une puce simulée, et l'inverse.

### 16.4 Le tapis de comptoir, le lien tapis-poste, la vente reliée aux pièces

- **Le tapis** : un lecteur à très courte portée (quelques centimètres) posé sur le comptoir et branché au boîtier. Il lit les puces
  des articles posés dessus. La plateforme garde, par tapis, les pièces présentes et depuis quand (projection des faits).
- **Lien tapis-poste** : un tapis sert UN poste de caisse (`loss_device.cash_register_id`), un poste a au plus un tapis, un boîtier
  peut porter plusieurs tapis. Le lien se fixe dans l'**assistant d'installation**, dont la suite s'allonge (§ 13.10) :
  `box` (un boîtier inscrit a donné signe de vie), `counter_mat` (un tapis déclaré a lu une puce d'essai), `mat_register`
  (l'administrateur choisit le poste, pose une puce sur le tapis, « Vérifier » : la lecture est bien venue de ce tapis),
  puis `printer` et `camera`, facultatives. Toujours sans case cochée : chaque étape se prouve par un fait.
- **Relier la vente aux pièces, après coup** : le module ne touche pas à la vente. Son analyse à curseur sur le journal de caisse
  (§ 3, toutes les 15 minutes, et à la demande) lit chaque vente : poste, heure, lignes. Pour chaque **article suivi** vendu
  (variante qui a au moins une pièce réelle portant une puce), elle prend une pièce de CETTE variante présente sur le tapis de CE
  poste dans les minutes qui précèdent la vente, **une pièce par article vendu**, et écrit le fait `PIECE_SOLD` : la pièce passe à
  `sold`. La base garantit qu'une pièce n'est vendue qu'une fois et qu'un article vendu n'a qu'une pièce. Un retour lu sur le
  tapis remet la pièce en rayon de la même façon (`PIECE_RETURNED`).
- **Ce qui reste** : un article suivi vendu **sans pièce lue** reste « à rattraper » (16.6). Une pièce posée sur le tapis et non
  vendue ne donne rien : le client a pu renoncer.
- **Une règle de plus, par poste et jamais par personne** : « articles suivis vendus sans lecture » sur une journée et un poste
  (tapis en panne, tapis non utilisé, puces retirées). Elle suit le circuit des règles (§ 4 : seuil réglable, aperçu, exercice).

### 16.5 La route « articles non lus avant paiement »

- **Qui l'appelle** : la caisse, avant le paiement, avec le droit du caissier. Elle vit donc sous les routes de la caisse (pas sous
  `/api/gestion`), dans le code du module. Corps : le poste et les lignes du panier (variante, quantité).
- **Réponse** : `{ active, reason, tracked, read, unread [{ productVariantId, name, quantity, missing }], extra, checkedAt }`.
  `unread` : les articles suivis du panier dont la pièce n'est pas sur le tapis de ce poste ; `extra` : le nombre de pièces posées
  sur le tapis qui ne sont pas dans le panier. Aucun nom de personne.
- **Une lecture, rien n'est écrit**, et **la vente n'est jamais bloquée** : la caisse affiche l'avis, le caissier pose l'article sur
  le tapis ou continue. Module éteint, poste sans tapis, tapis ou boîtier silencieux : `active: false` avec la raison, en 200 ;
  la caisse traite toute erreur ou tout retard comme « pas de contrôle ». Caisse hors ligne : pas de contrôle.
- Contrat à remettre à la session de la caisse (par le chef) avant le code.

### 16.6 Le rattrapage au portique

> **À reprendre dans le plan de L4** (demande du chef de projet, 16/11/2026, après la relecture de L3-b2a) : chaque vente suivie
> sans pièce « couvre », pendant la fenêtre du rattrapage, la sortie d'une pièce NON vendue de sa variante, tous postes confondus.
> Avec un étiquetage partiel, **une vente sans pièce peut donc absorber la sortie d'une pièce volée** du même article, et le
> constat « sortie sans vente » ne la verra pas. Deux parades à trancher au plan de L4 : (a) ne rattraper que pour les variantes
> dont TOUTES les pièces en rayon portent une puce (l'article vendu sans pièce ne peut alors être qu'une lecture manquée) ;
> (b) garder le rattrapage, mais ouvrir un constat « faible » chaque fois qu'il absorbe une sortie, pour qu'une série se voie.

- **Règle** (§ 14, point 5) : une pièce non vendue lue à la sortie (`ZONE_SEEN`, zone `exit`), alors qu'une vente récente de SA
  variante a encore un article sans pièce, est rattachée à cette vente : fait `PIECE_SOLD` avec la mention « rattrapage », **une
  seule pièce par article vendu**, la vente la plus ancienne d'abord. Pas de constat.
- **Dans les deux sens** : la vente peut être lue par le curseur après la lecture à la sortie ; le rattrapage se rejoue donc quand
  la vente arrive (lectures de sortie encore sans vente) comme quand la lecture arrive (ventes encore sans pièce).
- **En L3** la règle est codée et jouée par le simulateur et le lecteur à main ; le portique réel et le constat « sortie sans
  vente » sont L4, qui attendra que le curseur ait dépassé l'heure de la lecture avant de juger.

### 16.7 L'imprimante à puce du niveau pro

Le § 15 fait foi (chemin en sept temps, identifiant de 96 bits de la plateforme sans numérotation GS1, relecture avant toute
association, échecs, modèles visés). Ce que le plan ajoute :

- **Travail d'impression** : routes de l'administrateur pour le créer (pièces, imprimante, prix ou non), le suivre et l'annuler ;
  tables `loss_print_job` et ses lignes. Le boîtier prend ses travaux par le canal du 16.2 et renvoie un fait par étiquette
  (`TAG_WRITTEN` après relecture conforme, sinon `TAG_WRITE_FAILED` et son motif). Le langage de l'imprimante reste dans le
  boîtier : la plateforme envoie les textes, le code-barres et l'identifiant à écrire.
- **Verrou par mot de passe** (décision du propriétaire) : un mot de passe d'accès par site, tiré au hasard par la plateforme,
  **rangé chiffré** (clé de chiffrement hors de la base), jamais montré, jamais imprimé, **dans aucun journal ni aucun fait**.
  Le boîtier le reçoit avec le travail, par HTTPS, le garde en mémoire le temps du travail, ne l'écrit jamais sur son disque.
  Sur la puce : l'identifiant est verrouillé en écriture par ce mot de passe (jamais de verrou définitif), **et la zone qui porte
  le mot de passe est fermée à la lecture** (sans quoi n'importe quel lecteur le lirait). La lecture de l'identifiant reste
  ouverte à tous. **Le rang du mot de passe utilisé reste à la plateforme** (un nombre, gardé avec le travail d'impression :
  `loss_print_job.secret_rank`) ; il n'est pas écrit sur la puce. C'est lui qui servira à un changement de mot de passe futur
  (décision du chef de projet du 16/11/2026, après la relecture du logiciel du boîtier).
- **Condition ferme de l'essai du matériel** (chef de projet, 16/11/2026) : avant toute vraie pièce étiquetée, **toute la zone
  réservée de la puce**, le mot de passe d'accès ET le mot de passe de mise à mort, est verrouillée derrière le mot de passe
  d'accès. Personne d'autre ne doit pouvoir écrire un mot de passe de mise à mort, puis tuer la puce. Les commandes écrites dans
  le logiciel du boîtier ferment aujourd'hui la zone du mot de passe d'accès et verrouillent l'identifiant ; le verrou de la zone
  de mise à mort reste à écrire et à prouver, imprimante en main. Ce n'est pas une option : aucun travail d'impression réel
  n'est lancé chez un marchand tant que ce verrou n'est pas vérifié sur une puce.
- **Protection légère, dite telle quelle** : un mot de passe par site arrête la réécriture ordinaire, pas un attaquant équipé.
  Pas de contrôle des copies (décision du propriétaire, § 15).
- La gamme (`GET /materiel`) change son texte du niveau pro quand ce sous-lot est en ligne.

### 16.8 La caméra du comptoir

- **Elle ne déclenche jamais rien** (§ 14, point 4) : c'est un fait de puce ou de caisse qui demande un extrait. Jamais de
  reconnaissance des visages, de son, ni d'analyse de gestes (§ 1).
- **Cadre** : le tapis et le plan du comptoir, pas le visage du caissier ni un poste filmé en continu. Elle suit la notice aux
  salariés et un **interrupteur par pays et région** (§ 7), avec une affichette pour les clients.
- **Chemin** : la caméra (réseau local ou USB) est lue par le boîtier, qui garde sur son disque une boucle courte, **sans piste
  son**, et n'envoie rien en continu. Quand l'analyse trouve une vente avec des articles suivis non lus sur un poste qui a une
  caméra, la plateforme dépose une **demande d'extrait** (même file que les travaux d'impression) : de 2 minutes avant la vente à
  1 minute après. Le boîtier découpe, envoie (taille et durée plafonnées), et l'extrait devient une **preuve** du constat
  (`kind: clip`, empreinte SHA-256, fichier dans le stockage privé du site, jamais d'adresse publique).
- **Consultation** : administrateur seulement, depuis la fiche du constat, par une route authentifiée ; chaque visionnage est
  écrit au journal du module. **Conservation : 30 jours** (décision 6 du § 12) : le fichier est supprimé, la preuve garde son
  empreinte et dit « extrait expiré ».
- **L5** : l'IA ne lira que cet extrait. Rien de cela en L3.
- **Avant le premier client** : avis de l'avocat (§ 14, point 6, et décision 15).

### 16.9 La machine de test et le simulateur

- **Simulateur** : le logiciel du boîtier lancé avec des appareils simulés (tapis, lecteur de sortie, imprimante à puce qui peut
  rater une écriture, caméra qui filme une mire datée). Il joue des **scénarios écrits** : vente normale ; article non posé sur le
  tapis ; rattrapage à la sortie ; réseau coupé deux heures puis rejeu ; horloge décalée ; lot envoyé deux fois ; écriture de puce
  ratée puis réussie ; clé révoquée.
- **Contrat partagé** : les lots de faits de ces scénarios sont des fichiers JSON du dépôt du backend
  (`tests/Fixtures/pertes/boitier/`), comme les exemples remis au web. Les tests du backend les rejouent sans boîtier ; ceux du
  boîtier vérifient qu'il produit les mêmes. Un changement de contrat casse un test des deux côtés.
- **Machine de test** : une machine virtuelle Debian montée par un script : installer le paquet, inscrire le boîtier, lancer le
  simulateur, couper et rendre le réseau, mettre à jour le paquet, le retirer. Elle prouve le paquet et l'inscription, ce qu'aucun
  test du backend ne peut faire. Elle ne vise **jamais un site de client** (16.12, question 9).
- **Pour le gérant** : le mode exercice gagne un « boîtier simulé » fabriqué par le serveur (aucun matériel, aucun boîtier) : il
  joue les mêmes scénarios dans les tables du module, marqués exercice.

### 16.10 Découpage proposé

| Sous-lot | Contenu | Dépend de |
|---|---|---|
| **L3-a** | clé de boîtier, inscription, jeton, canal des faits, boîtiers et appareils, étape `box` de l'assistant, boîtier simulé d'exercice, fichiers du contrat | L2 |
| **L3-b** | tapis, lien tapis-poste, vente reliée aux pièces, route avant paiement, rattrapage, règle « vendus sans lecture » | L3-a |
| **L3-c** | travaux d'impression, imprimante à puce, mot de passe d'accès | L3-a |
| **L3-d** | caméra du comptoir : demandes d'extrait, dépôt, preuve, conservation, interrupteur par pays | L3-b |
| **Boîtier** (autre dépôt, créé au moment du code) | paquet, conteneur, simulateur, machine de test | contrat de L3-a |

Une migration additive par sous-lot (la première : `Version20270113100000`, réservée auprès de « appli degestion backend »).
L3-a et L3-b se jouent entièrement sans matériel ; L3-c et L3-d se codent sur le simulateur et demandent ensuite la campagne
d'essai sur du vrai matériel (§ 13.7, risque 7) avant tout client.

### 16.11 Risques

1. **Une entrée nouvelle, tenue par une machine** : boîtier volé, jeton copié. Parade : une clé par boîtier, jeton court lié à la
   machine, révocation immédiate, aucun droit de lecture, débit limité, routes fermées sans le droit du module.
2. **Faits faux ou rejoués** : lots idempotents, puce inconnue refusée, appareil non déclaré refusé, heure douteuse écartée des
   rattachements, exercice et réel étanches.
3. **Mauvais rattachement d'une vente** (deux articles pareils, tapis du voisin). Parade : courte portée, même poste, même
   variante, fenêtre courte, une pièce par article, garanties de la base ; le fait dit par quel chemin la pièce a été rattachée.
4. **La caisse dépend du module** : jamais. La route d'avant paiement ne bloque rien et la caisse ignore ses pannes.
5. **Volume** : départs et arrivées seulement, signe de vie non gardé, lots plafonnés, file du boîtier bornée.
6. **Secret** : le mot de passe des puces ne sort que vers le boîtier, pour un travail ; test : il n'apparaît dans aucune réponse
   de l'administrateur, aucun journal, aucun fait.
7. **Vidéo et vie privée** : interrupteur par pays, notice, cadre, pas de son, extraits courts et datés, 30 jours, avocat.
8. **Matériel jamais vu** : tapis, imprimante et caméra sont bâtis sur le simulateur ; la campagne d'essai tranche les détails.
9. **Code partagé** : clés d'activation, `security.yaml`, routes de la caisse : écrit avec les sessions qui les tiennent.

### 16.12 Questions au propriétaire, avec la proposition

1. **Machine du boîtier** : mini-ordinateur ou carte de type Raspberry Pi ? *Proposition : le paquet accepte les deux ; modèle
   conseillé : un mini-ordinateur sans ventilateur à disque SSD (une carte mémoire s'use), à confirmer à l'achat.*
2. **Mises à jour du boîtier** : *proposition : automatiques, la nuit, hors des heures du magasin, avec retour à la version
   d'avant si le boîtier ne redémarre pas bien ; l'application montre la version.*
3. **Dépannage à distance** : *proposition : aucun accès à distance en L3 ; le boîtier envoie son état technique, sans donnée
   personnelle, et l'assistant dit quoi vérifier.*
4. **Articles non lus avant le paiement** : simple avis au caissier, ou validation d'un responsable ? *Proposition : un avis
   seulement, la vente n'est jamais bloquée ; la validation se décidera avec les alertes en caisse (L5).*
5. **Fenêtres** : *proposition : une pièce compte pour la vente si elle était sur le tapis dans les 10 minutes avant ; le
   rattrapage à la sortie vaut 30 minutes après la vente ; deux réglages du module.*
6. **Règle « articles suivis vendus sans lecture »** : *proposition : par poste et par jour, jamais par personne ; seuil de départ :
   5 articles et 20 % des articles suivis vendus ce jour-là sur ce poste.*
7. **Caméra du comptoir** : *proposition : éteinte d'office en France et au Québec jusqu'à l'avis de l'avocat, allumable aux
   États-Unis ; extraits de 3 minutes ; suppression du fichier après 30 jours par un minuteur (c'est une suppression
   automatique : il faut votre accord) ; téléchargement permis à l'administrateur, écrit au journal.*
8. **Mot de passe des puces** : *proposition : envoyé au boîtier avec chaque travail et gardé en mémoire seulement ; un changement
   de mot de passe ne vaut que pour les puces écrites ensuite.*
9. **Machine de test** : où tourne-t-elle, et vers quel site ? *Proposition : hors du serveur de production, vers un site de
   répétition isolé ; `demo` seulement avec votre accord exprès, en mode exercice.*
10. **Droit de magasinier** (promis pour L3 à la question 7 du § 13.8) : *proposition : un rôle limité à associer une puce, chercher
    une puce et imprimer les étiquettes de pièces, sans les constats ni les réglages ; codé après L3-b, en petit lot.*
11. **Ordre** : *proposition : L3-a, L3-b, puis L3-c ; L3-d en dernier, après l'avis de l'avocat ; le dépôt du boîtier est créé
    quand le contrat de L3-a est validé.*

### 16.13 Lot L3-a codé : clé, inscription, canal des faits, boîtier simulé (15/11/2026)

Code : `Services/LossBoxService` (l'administrateur), `Services/LossBoxChannelService` (le boîtier), `Services/LossBoxToken`,
`Services/LossBoxKey`, `Services/LossBoxLimits` (plafonds), `Services/LossHealthService`, `LossBoxGuardSubscriber`,
`Repository/LossBoxRepository`, `UseCase/LossBoxUseCase` et `LossBoxChannelUseCase`, `Controller/LossBoxController` et
`Controller/LossBoxChannelController`. Migration `Version20270113100000` (script `scripts/migrate_all_v2_pertes_l3a.sh`) : tables
`loss_box`, `loss_device`, `loss_box_event` ; trois colonnes facultatives sur `loss_piece_event` (`box_id`, `device_id`,
`received_at`) et une sur `loss_setting` (`silent_after_seconds`). Exemples de chaque appel, demande et réponse : `tests/Fixtures/pertes/boitier/` (un fichier par appel ; `admin-…` pour les
écrans, `canal-…` pour le logiciel du boîtier), vérifiés par `LossPreventionBoxExamplesTest`. Code d'écran : `pertes.boitiers`.

**Ce que le plan disait autrement** (détails techniques tranchés au code) :
- la clé du boîtier vit dans `loss_box`, pas dans `tenant_app_keys` : le module n'écrit que dans ses tables (§ 1), une clé de boîtier
  n'active aucune application, et un boîtier d'exercice n'a pas de clé. Conséquence : la machine reçoit **l'adresse du site et la
  clé** (`enrolment.host`, `enrolment.key`, ou les deux d'un coup dans `enrolment.code`, « adresse#clé », à mettre dans un code QR) ;
- le contrôle d'accès est une garde du module et non un rôle (`ROLE_LOSS_BOX` n'existe pas) : le préfixe est `PUBLIC_ACCESS` dans
  `security.yaml` et fermé par `LossBoxGuardSubscriber` ; le contrôleur refuse de travailler sans le boîtier posé par la garde ;
- le canal n'accepte en L3-a que deux faits de pièces (`SPOT_CHECK`, `ZONE_SEEN`). Les pannes d'appareils ne sont pas des faits
  envoyés : la plateforme les déduit du signe de vie. `COUNTER_SEEN`, `COUNTER_LEFT`, `TAG_WRITTEN`, `CLIP_READY` viendront avec
  leurs lots ; le boîtier lit dans ses réglages (`facts.types`) ce qu'il a le droit d'envoyer.

**Routes de l'administrateur** (`/api/gestion/pertes`, `ROLE_ADMIN`, garde de la gestion puis du module ; erreurs à la forme de la
gestion ; `?locale=fr|en`) :

| Route | Réponse | Refus |
|---|---|---|
| `GET /boitiers` | `{ items [box], total, limit: 20, exercise: false, screen }` | |
| `POST /boitiers` `{ name, place? }` | 201 `{ box, key, enrolment { host, key, code, text } }` : **la clé n'est rendue qu'ici** | 422 `fields.name` / `place` ; 409 `BOX_LIMIT_REACHED { limit }` (20 boîtiers non révoqués) |
| `GET /boitiers/{id}` | la fiche : `box` + `events [{ id, type, label, at, atLabel, device, silentSeconds, byUser }]` (50 derniers) + `factsReceived` | 404 `BOX_NOT_FOUND` |
| `PUT /boitiers/{id}` `{ name?, place? }` | la fiche | 422, 404 |
| `POST /boitiers/{id}/cle` `{ confirm: true }` | `{ box, key, enrolment }` : nouvelle clé, la machine d'avant est détachée, ses jetons ne valent plus rien | 422 `fields.confirm` ; 409 `BOX_REVOKED` ; 404 |
| `POST /boitiers/{id}/revoquer` `{ confirm: true }` | la fiche ; définitif ; révoquer deux fois ne fait rien | 422, 404 |

`box` : `{ id, name, place, status: pending | active | revoked, statusLabel, online, onlineLabel, lastSeenAt, lastSeenLabel,
silentSeconds, version, arch, enrolledAt, clockOffsetSeconds, clockDoubt, keyHint, exercise, devices [{ id, serial, kind: counter_mat
| reader | printer | camera, kindLabel, model, status: ok | down, statusLabel, zone, zoneLabel, lastSeenAt }], createdAt, screen }`.
`online` se calcule (boîtier actif dont le dernier signe de vie est plus récent que le délai de silence du site, 5 minutes par défaut) ; `silentSeconds` : depuis quand un boîtier actif
se tait ; `clockDoubt` : son horloge s'écarte de plus d'une minute. **Aucune lecture ne rend la clé, son empreinte ni celle de la
machine.**

**Routes d'exercice** (boîtier simulé ; ouvertes sans le droit du module, `LossPreventionModuleSubscriber::OPEN_ROUTES`) :
`GET /exercices/boitiers` (`{ items, total, limit: 3, exercise: true, screen, scenarios [{ code, label, text }] }`),
`POST /exercices/boitiers` (aucun corps ; 201 `{ box, scenarios }` : un boîtier déjà « inscrit », avec un lecteur de sortie et un
lecteur à main simulés ; 409 `EXERCISE_LIMIT_REACHED { limit: 3 }`), `GET /exercices/boitiers/{id}`,
`POST /exercices/boitiers/{id}/scenarios` `{ scenario }` → `{ scenario, written, box }` (`heartbeat`, `network_cut`, `device_down`,
`device_up`, `spot_check`, `zone_exit` ; 422 `fields.scenario` ; 409 `EXERCISE_NO_PIECE` quand aucune pièce d'exercice ne porte de
puce), `DELETE /exercices/boitiers` → `{ deleted }`. Un boîtier simulé n'a ni clé ni jeton (même signé par le serveur, un jeton à
son nom est refusé) ; ses faits portent `exercise = true` et n'entrent dans aucun chiffre réel ; `DELETE /exercices/pieces` emporte
les lectures faites par un boîtier simulé avec les pièces.

**Tableau de santé du matériel** (demande du propriétaire, 15/11/2026 ; `Services/LossHealthService`) :

| Route | Réponse | Refus |
|---|---|---|
| `GET /sante` | `{ devices [{ code, kind, label, zone, cashRegisterId, state, stateLabel, lastSeenAt, silentSince, silentSeconds, hint }], summary { ok, pending, down, absent, allOk, label }, thresholdSeconds, exercise, screen, checkedAt }` | |
| `PUT /sante/seuil` `{ silentAfterSeconds }` | `{ thresholdSeconds, default: 300, min: 180, max: 3600 }` | 422 `fields.silentAfterSeconds` : `REQUIRED`, `MUST_BE_INTEGER`, `PARAM_OUT_OF_RANGE` |
| `GET /exercices/sante` | la même forme, sur les boîtiers simulés ; ouverte sans le droit du module | |

- **États** : `ok` (un signe de vie plus récent que le délai de silence) ; `pending` (branché mais pas encore vérifié : en L3-a, un
  boîtier qui attend sa machine) ; `down` (silencieux : `silentSince`, `silentSeconds`, et `hint` dit quoi essayer : alimentation,
  câble réseau, connexion de la boutique) ; `absent` (pas encore installé). « À vérifier » compte ce qui est branché et ne va pas
  (`pending` et `down`) ; un appareil pas encore installé n'est pas une panne. `summary.allOk` est vrai quand quelque chose est
  installé et que rien n'est à vérifier ; `summary.label` est le texte du bandeau : « Tout est en ordre », « 1 appareil à
  vérifier », ou « Aucun appareil installé » (une boutique peut suivre ses pièces sans boîtier : pas d'alarme permanente).
- **En L3-a le tableau ne connaît que le boîtier** : une ligne par boîtier non révoqué (`code: box-<id>`, `kind: box`) ; sans aucun
  boîtier, une seule ligne `code: box`, `state: absent`. Le tapis (L3-b), l'imprimante (L3-c), la caméra (L3-d) et les antennes (L4)
  y ajouteront leurs lignes (`kind: mat | printer | camera | antenna`, avec `zone` ou `cashRegisterId`).
- **Légère** : l'écran la relit toutes les 5 secondes ; elle fait deux lectures (les boîtiers, le délai) et n'écrit rien. À voir
  côté écrans : cette relecture ne doit pas rafraîchir le jeton de l'utilisateur à chaque passage (une session laissée ouverte
  sur cet écran doit s'éteindre comme les autres).
- **Le délai de silence se règle par site** (`loss_setting.silent_after_seconds`, 300 par défaut, 180 à 3600 : le boîtier donne
  signe de vie chaque minute, deux appels perdus ne font pas un silence ; `null` revient au défaut ; une valeur hors bornes est
  ramenée dans les bornes à la lecture ; écrit au journal du module, `SETTINGS_CHANGED`). C'est le même délai partout : la pastille, `online` de la fiche d'un
  boîtier, l'étape de l'assistant, et le fait `BOX_BACK` au retour d'un silence.
- **Pour plus tard, avec le lot NOTIF, sans code aujourd'hui** : un appareil qui passe à `down` pendant les heures d'ouverture
  donnera une notification de la famille « urgent ».

**Assistant d'installation** (§ 13.10) : la réponse gagne `optionalSteps [{ code: box, title, text, image, status: todo | done,
verify, state, hint }]`. L'étape `box` reprend l'état du boîtier au tableau de santé : elle est faite quand un boîtier de ce genre
est inscrit ET donne signe de vie (`state: ok` ; un seul boîtier en ordre suffit) ; sinon `state` et `hint` disent où l'on en est
et quoi essayer (aucun boîtier, boîtier qui attend sa machine, boîtier silencieux depuis N minutes). Elle est rendue à part parce
qu'une boutique peut poser et lire ses puces sans boîtier : elle ne compte ni dans `progress` ni dans `complete`, et `steps` ne
change pas. `POST /installation/etapes/box/verifier` répond `{ code, verified, hint }` avec le même conseil.

**Routes du boîtier** (`/api/pertes/boitier`, jamais appelées par une personne ; en-tête `X-Loss-Box: <jeton>` ; en-tête
`X-Tenant-Host` du site, comme toute l'API ; erreurs `{ error, code, message, … }`) :

| Route | Réponse | Refus |
|---|---|---|
| `POST /inscription` `{ key, machine, version, arch: amd64 \| arm64 }` (sans jeton) | `{ box { id }, token, expiresAt, serverTime, settings }` | 422 `VALIDATION_FAILED { fields }` ; 404 `BOX_KEY_INVALID` (clé inconnue, ou saisie qui n'en est pas une : même réponse) ; 403 `BOX_REVOKED` ; 409 `BOX_KEY_ALREADY_ENROLLED` (la clé est liée à une autre machine) |
| `POST /jeton` `{ machine }` (jeton encore bon, ou expiré depuis moins de 30 jours) | `{ token, expiresAt, serverTime }` | 401 `BOX_TOKEN_INVALID` (il faut une nouvelle inscription) ; 403 `BOX_REVOKED` |
| `POST /signe-de-vie` `{ now, machine, version?, lastServerTime?, devices [{ serial, kind, model?, status }] }` (`lastServerTime` : lot L3-b1, § 16.15) | `{ serverTime, clockOffsetSeconds, clockDoubt, token: { token, expiresAt } \| null, refusedDevices [{ serial, code }], resendMats, settings }` | 422 `VALIDATION_FAILED { fields }` (`now`, `machine`, `version`, `lastServerTime`, `devices` : liste obligatoire, `devices[i].serial \| kind \| status \| model`) ; 401 `BOX_TOKEN_INVALID` (ce n'est pas la machine inscrite) ; 429 `RATE_LIMITED` (trop d'empreintes de machine fausses) |
| `GET /reglages` | `{ box { id }, serverTime, heartbeatSeconds: 60, facts { types, maxBatch: 200 }, zones, devices [{ id, serial, kind, zone, cashRegisterId }] }` | |
| `POST /faits` `{ facts [{ key, type, at, device, zone?, seq?, data { identifier, technology? } }] }` (1 à 200) | `{ received, accepted, duplicates, refused, results [{ index, key, status: accepted \| duplicate \| refused, code }], serverTime }` | 422 `VALIDATION_FAILED { fields.facts }`, `FACTS_LIMIT_EXCEEDED { max, received }` ; 413 `PAYLOAD_TOO_LARGE` (256 Ko) ; 429 `RATE_LIMITED` (20 000 faits par boîtier et par heure) |

Toute route du canal : 401 `BOX_TOKEN_REQUIRED` (pas d'en-tête), `BOX_TOKEN_INVALID` (signature, site, boîtier, génération),
`BOX_TOKEN_EXPIRED` (appeler `/jeton`) ; 403 `BOX_REVOKED` (le boîtier s'arrête) ; 403 `MODULE_INACTIVE` (le site n'a plus le droit du
module : le boîtier garde sa file et réessaie ; sur ce préfixe la réponse est nue, `{ error, code, message }`, sans rien dire de
l'offre du site) ; 429 `RATE_LIMITED { retryAfterSeconds }`. **Aucune réponse faite au boîtier ne porte de nom** : ni article, ni
personne, ni même le nom que l'administrateur a donné au boîtier.

- **Clé et machine** : la clé a 60 bits ; l'aperçu gardé (`keyHint`) n'en montre que les deux premiers caractères. La base ne garde
  de la clé et de la machine qu'une empreinte **liée au site et au secret de l'application** (`LossBoxToken::keyHash`,
  `machineHash`) : une copie de la base ne donne ni l'une ni l'autre, et une clé copiée avec une base (site créé d'après le modèle,
  répétition) ne vaut pas sur un autre site.
- **Inscription** : `machine` est une empreinte que le boîtier fabrique de sa machine (16 à 128 caractères). La clé se lie à la
  première machine ; la même machine peut se réinscrire (la génération avance, l'ancien jeton meurt ; si elle se taisait depuis plus
  longtemps que le délai de silence, le fait `BOX_BACK` dit le trou) ; une autre machine est refusée tant que l'administrateur n'a
  pas remis la clé à zéro.
- **Jeton** : `b<boîtier>.<génération>.<échéance>.<signature>`, signé avec le secret de l'application pour ce site ; 24 heures ; le
  signe de vie en rend un neuf dès la mi-vie. **Chaque appel relit le boîtier** (réel, actif, même génération) : une révocation ou une
  remise à zéro de la clé vaut à l'appel suivant ; le signe de vie et un lot de faits le relisent encore SOUS VERROU avant d'écrire
  (un appel en route pendant une révocation n'écrit rien). Le jeton ne donne que les cinq routes ci-dessus : présenté à une route de
  la gestion ou de la caisse, seul ou comme jeton d'utilisateur, il reçoit 401 (test). **Un jeton copié ne tient pas un boîtier en
  vie** : le signe de vie et l'échange de jeton exigent l'empreinte de la machine inscrite. `/faits` et `/reglages` ne la demandent
  pas : un jeton copié peut encore envoyer des faits jusqu'à son échéance (24 heures au plus), dans la limite de 20 000 par heure.
- **Si le secret de l'application change**, les jetons meurent, et avec eux les empreintes des clés et des machines : chaque
  boîtier de chaque site doit alors recevoir une nouvelle clé (remise à zéro par l'administrateur) et se réinscrire. **Avant toute
  rotation de ce secret** (elle est prévue dans la campagne de mise en production) : prévoir une nouvelle clé pour chaque boîtier,
  ou une période où l'ancien et le nouveau secret sont acceptés tous les deux. Au 16/11/2026 aucun boîtier réel n'existe : rien
  n'est à faire. La garde est aussi dans `docs/mise-en-ligne.md` (tenue par « appli degestion backend »).
- **Signe de vie** : toutes les 60 secondes. Il met à jour le dernier contact, la version, l'écart d'horloge (`now` du boîtier moins
  l'heure du serveur) et l'état des appareils. **Un appareil que la plateforme ne peut pas prendre n'empêche jamais le signe de
  vie** : il est rendu dans `refusedDevices`, avec `MISMATCH` (le genre d'un appareil ne change pas : celui qu'on connaissait sous
  ce numéro n'est plus là, il est noté en panne, une fois, `SOURCE_DOWN { reason: mismatch }`, et l'administrateur le voit dans la
  fiche du boîtier), `DUPLICATE` (deux appareils au même numéro de série : le premier compte) ou `TOO_MANY` (plus de 16 appareils
  dans un signe de vie : les 16 premiers sont pris ; ou le boîtier a déjà annoncé 64 appareils au fil du temps : les remplacés
  comptent, rien ne se supprime). `DUPLICATE` et `TOO_MANY` ne sont dits qu'au boîtier (son journal) : les faits d'un lecteur non
  pris reçoivent ensuite `DEVICE_UNKNOWN`. Retirer un appareil remplacé viendra avec le lot L3-b. **Il n'écrit un fait que pour un changement** : `DEVICE_SEEN` (première annonce d'un
  appareil), `SOURCE_DOWN` et `SOURCE_UP` (`reason: reported`, ou `absent` quand un appareil connu n'est plus annoncé), `BOX_BACK
  { silentSeconds, since }` au retour d'un silence plus long que le délai du site (5 minutes par défaut). Les autres faits de `loss_box_event` viennent de
  l'administrateur ou de l'inscription : `BOX_CREATED`, `BOX_ENROLLED { version, arch, again }`, `BOX_KEY_RESET`, `BOX_REVOKED`.
- **Faits** : `key` (8 à 48 caractères, `A-Z a-z 0-9 _ -`) est la clé d'idempotence, propre au boîtier (gardée sous la forme
  `b<boîtier>:<clé>`) ; `at` est l'heure du boîtier, ISO 8601 avec son décalage ; `device` est le numéro de série d'un lecteur que le
  boîtier a annoncé dans un signe de vie ; `zone` est obligatoire pour `ZONE_SEEN`, facultative pour `SPOT_CHECK` ; `identifier` est
  nettoyé comme au § 13.9 (la technologie est facultative). **La clé doit être aléatoire** (un UUID) : elle est gardée par boîtier,
  pas par machine ; un logiciel qui numéroterait ses faits depuis 1 verrait ceux d'une machine de remplacement répondus « déjà connu ». Refus par fait, à ne pas renvoyer : `FACT_INVALID`, `FACT_TYPE_UNKNOWN`,
  `DEVICE_UNKNOWN`, `DEVICE_KIND_INVALID` (seul un lecteur lit une puce), `ZONE_INVALID`, `TAG_INVALID`, `TAG_UNKNOWN` (aucune puce du
  site ne porte cet identifiant), `TAG_SIMULATED`. Un fait accepté est une ligne de `loss_piece_event` (`source: box`, la pièce et la
  puce, le boîtier, l'appareil, l'heure du boîtier dans `occurred_at`, l'heure de réception dans `received_at`, `data { zone, seq,
  tagRetired, clockDoubt }`) ; il apparaît dans la fiche de la pièce, qui montre toute sa vie (créée, puces posées, retirée) et
  ses 200 dernières lectures seulement. La lecture d'une puce
  **retirée** est gardée et dite (`tagRetired`) : les lots suivants ne s'en serviront pas pour rattacher une vente. **Aucun état de
  pièce ne change en L3-a.**
- **Heure douteuse** : `clockDoubt: true` sur un fait reçu quand l'horloge du boîtier n'a pas encore été mesurée, quand elle s'écarte
  de plus d'une minute, quand le fait est daté de plus d'une minute dans le futur, ou de plus de 8 jours dans le passé (la file du
  boîtier en garde 7). Une date qui n'existe pas (30 février) n'est pas une heure : `FACT_INVALID`. Les lots L3-b et L4 n'utiliseront pas un tel fait
  pour rattacher une vente.
- **Débit** (`LossBoxLimits`) : inscription et échange de jeton, 20 essais par site et par adresse et 60 par adresse, sur 15
  minutes ; 600 appels par boîtier sur 5 minutes ; 20 000 faits envoyés par boîtier et par heure, comptés à l'arrivée du lot (les
  rejeux et les faits refusés comptent ; un boîtier qui revient d'une coupure vide sa file à ce rythme ; le 429 porte
  `Retry-After`). **Jetons refusés** : la garde vérifie d'abord la signature, qui ne coûte aucune lecture ; un jeton absent ou mal
  signé reçoit 401 et n'entame aucun compte ; un jeton bien signé mais refusé (expiré, révoqué, d'une génération passée) est compté
  par boîtier ET par génération, 60 sur 15 minutes, puis arrêté avant la lecture de la base. **Jamais par adresse** : les boîtiers
  d'une boutique sortent par la même adresse que le Wi-Fi de ses visiteurs, et du bruit envoyé de là ne doit pas les faire taire ;
  une ancienne machine qui insiste avec le jeton d'avant ne gêne pas celle qui l'a remplacée. **Empreintes de machine fausses**
  présentées avec un bon jeton (signe de vie, échange de jeton) : 10 par boîtier sur 15 minutes, puis le boîtier attend, même avec
  la bonne (un jeton copié ne sert pas à deviner la machine).
- **La base garde elle-même** (migration) : une clé pour un seul boîtier ; un vrai boîtier a une clé, un boîtier d'exercice n'en a
  pas ; un boîtier actif réel est lié à une machine ; un boîtier révoqué ne revient pas, et porte sa date de révocation ; la
  génération commence à 1, ne recule pas, et avance quand la clé ou la machine change ; un appareil et un fait sont du même genre
  (réel ou exercice) que leur boîtier ; un fait désigne un appareil de SON boîtier, tenus le temps de l'écriture ; les genres de
  faits de boîtier sont connus ; un fait de pièce venu d'un boîtier dit sa source, son heure de réception et sa clé, ne vient jamais
  d'un utilisateur, et une clé au nom d'un boîtier (`b<boîtier>:`) ne s'écrit que pour lui ; les faits sont en ajout seul ; rien ne
  se supprime, sauf l'exercice par sa porte, dans l'ordre faits, appareils, boîtiers.
- **Retour arrière de la migration** : il retire les tables et les colonnes du lot, mais les faits de pièces déjà reçus des boîtiers
  restent dans `loss_piece_event` (sans leur boîtier ni leur appareil, avec leur clé) : rien ne se supprime dans cette table. Si la
  migration est reposée ensuite, ces faits ne sont plus comptés pour leur boîtier et leur rejeu répond « déjà connu ».
- **Relecture indépendante** (15/11/2026) : aucun défaut sur la règle « le jeton du boîtier ne lit ni les ventes, ni les clients,
  ni les constats » ; corrigés : empreintes de la clé et de la machine liées au site et au secret, aperçu de la clé réduit, réponse
  nue quand le module manque, relecture du boîtier sous verrou avant d'écrire des faits, machine exigée au signe de vie, plafond des
  jetons refusés et des faits par heure, appareils refusés sans bloquer le signe de vie, liste des appareils obligatoire, code de
  la révocation en course, fait de retour à la réinscription, renommage sous verrou, dates strictes, gardes de la base durcies.
  Seconde passe sur ces corrections et sur le tableau de santé : un défaut nouveau, corrigé (le plafond des jetons refusés, compté
  par adresse, permettait de faire taire les boîtiers d'une boutique depuis son réseau : il est compté par boîtier et par
  génération, après la signature) ; corrigés aussi : plafond des empreintes de machine fausses, appareil au genre changé noté en
  panne, plus de 16 appareils sans refus de tout l'appel, fiche d'une pièce qui garde sa vie entière, bandeau sans alarme quand
  rien n'est installé, délai de silence d'au moins 3 minutes. Mutations ciblées : 106 jouées, 103 tuées, aucun défaut de code (une
  assertion de test mal écrite, corrigée ; deux mutations sans effet : la garde écartée, la relecture de la fiche refuse quand
  même et la transaction s'annule). Laissé, connu : en SQL direct on peut encore écrire un fait pour un
  boîtier en attente ou révoqué, ou repasser un boîtier d'actif à en attente sans avancer sa génération (le code ne le fait pas).
- **Tests** : `LossPreventionBoxTest` (administrateur ; inscription, garde, jeton, signe de vie, faits rejoués, « ne lit rien
  d'autre », appel en route pendant une révocation ; plafonds ; contrôleur appelé sans la garde ; boîtier simulé ; tableau de
  santé et délai réglable ; gardes de la base et rejeu de la migration), `LossPreventionBoxExamplesTest`.

### 16.14 Ajouts du propriétaire pour L3 et L4 (15/11/2026, relayés par le chef de projet)

1. **Une étape de l'assistant par appareil branché sur le boîtier**, chacune avec son « Vérifier » prouvé par des faits du boîtier :
   - L3-a : le boîtier est inscrit et donne signe de vie (**codé**, étape `box`, § 16.13) ;
   - L3-b : le tapis est lu et lié à son poste ;
   - L3-c : l'imprimante écrit et relit la puce de l'étiquette d'essai ;
   - L3-d : la caméra envoie un extrait d'essai ;
   - L4 : chaque antenne lit une puce d'essai dans la bonne zone.

   Quand une vérification échoue, l'étape dit quoi essayer : le câble, l'alimentation, un boîtier silencieux depuis N minutes.
2. **Un guide de l'installateur à part, illustré**, dans le dépôt du boîtier, en français et en anglais : la clé USB d'installation,
   Debian, le paquet `.deb`, la saisie de la clé du boîtier ; pour le Raspberry Pi, une image toute prête. À écrire à la création du
   dépôt du boîtier.
3. **Un voyant d'état sur le boîtier** : connecté, en attente d'inscription, en panne. Le détail se lit dans l'écran
   `pertes.boitiers`. Pas de page web locale, puisqu'aucun port n'est ouvert. À faire dans le dépôt du boîtier, à sa création.
4. **Un tableau de santé du matériel**, une pastille par appareil (**codé** pour le boîtier, § 16.13, « Tableau de santé ») : chaque
   lot de matériel y ajoute sa ligne ; chaque étape de l'assistant reprend l'état de son appareil ; le bandeau dit « Tout est en
   ordre » quand `allOk` est vrai ; plus tard, avec NOTIF, un appareil qui tombe pendant les heures d'ouverture notifie en « urgent ».
5. **L'offre du boîtier** (décision du propriétaire ; à reprendre dans le plan de L6 et dans le guide ; sans code) :
   - **le boîtier prêt à brancher**, vendu pour les gammes pro et standard : un mini-ordinateur préinstallé, livré avec sa clé
     préparée ; le commerçant le branche, saisit la clé, et la pastille passe au vert ; matériel et logiciel sont garantis et
     assurés par notre support ;
   - **le logiciel seul**, que le client installe, pour la gamme bricoleur ou un client à l'aise : le paquet `.deb`, l'image
     Raspberry Pi et un guide très simple (le système, les commandes à copier une par une, le branchement de chaque appareil) ; la
     liste du matériel que nous avons essayé ; un avertissement visible dans le guide et dans les conditions de vente :
     « Installation à vos risques : nous ne garantissons pas le fonctionnement sur votre matériel ; notre support couvre le
     logiciel seulement » ;
   - la formulation de cet avertissement est à faire valider par l'avocat ; les prix sont fixés en L6 par le propriétaire ;
   - le guide de l'installateur du dépôt du boîtier sert les deux offres : une version courte pour le boîtier prêt à brancher, la
     version complète, avec les commandes, pour le logiciel seul.

### 16.15 Lot L3-b1 codé : tapis de comptoir et route de la caisse (16/11/2026)

L3-b est coupé en deux (découpage validé par le chef de projet) : **L3-b1**, ci-dessous, et **L3-b2** (la vente reliée aux pièces
après coup par l'analyse, le rattrapage au portique, la règle « articles suivis vendus sans lecture » et ses fenêtres réglables).
Code : `Services/LossCounterService` (la question de la caisse), `Controller/LossPosController`, et les ajouts à
`LossBoxChannelService` (faits du tapis, présence), `LossBoxService` (relier, retirer un appareil, tapis simulé),
`LossHealthService` (ligne du tapis), `LossInstallationService` (étape `counter_mat`). Migration `Version20270114100000` (script
`scripts/migrate_all_v2_pertes_l3b1.sh`) : table `loss_counter_presence` ; index unique « un poste a au plus un tapis en service » ;
trois gardes du lot L3-a remplacées par leur version élargie. **Aucune vente n'est lue ni écrite, aucun état de pièce ne change.**

**Le tapis, vu du boîtier** (même canal, § 16.13) :
- il se déclare dans le signe de vie comme un appareil `counter_mat` ;
- il envoie deux faits de plus, que ses réglages annoncent (`facts.types`) : `COUNTER_SEEN` (une puce arrive sur le tapis) et
  `COUNTER_LEFT` (elle le quitte). Même forme que les autres faits ; `zone` vaut `counter` (facultative, toute autre valeur :
  `ZONE_INVALID`). **Chacun ses faits** : un tapis n'envoie que ceux-là, un lecteur que `SPOT_CHECK` et `ZONE_SEEN`
  (`DEVICE_KIND_INVALID` sinon). Une arrivée, un départ : le tapis ne redit pas à chaque seconde ce qu'il voit ;
- **quand la plateforme a fermé ce qu'elle croyait posé, elle le dit au boîtier** : la réponse du signe de vie porte `resendMats`,
  la liste des numéros de série des tapis dont le boîtier doit REDIRE le contenu (`COUNTER_SEEN` pour chaque puce encore posée) :
  au retour d'un silence, d'une panne ou d'un retrait du tapis, ou quand l'horloge du boîtier redevient juste. Le boîtier lit
  aussi dans ses réglages `silentAfterSeconds`, le délai de silence du site ;
- **une réponse perdue ne perd pas cette demande** : dans chaque signe de vie, le boîtier renvoie dans `lastServerTime` le
  `serverTime` de la DERNIÈRE réponse qu'il a lue à une inscription ou à un signe de vie (et à rien d'autre). S'il ne correspond
  pas au dernier signe de vie que la plateforme a traité (la réponse s'est perdue en route), ou s'il manque (le boîtier vient de
  redémarrer : il ne le garde pas d'un démarrage à l'autre), on ne sait plus ce que le boîtier sait : les présences de ses tapis
  se ferment et `resendMats` les nomme tous, à chaque signe de vie, jusqu'à ce qu'il ait lu une réponse. Un boîtier qui n'envoie
  jamais `lastServerTime` redit donc tout à chaque signe de vie : le champ est à envoyer dès qu'un tapis est branché ;
- une puce refusée `TAG_UNKNOWN` sur un tapis (posée avant d'être associée) n'est pas à renvoyer : elle sera redite au prochain
  `resendMats`, ou quand on la reposera.

**La présence** (`loss_counter_presence`) est la projection de ces faits, tenue par le serveur : une puce est sur un tapis depuis
`since`, jusqu'à `until` (nul tant qu'elle y est). Elle n'est la preuve de rien : les faits le sont. Règles :
- une arrivée ouvre une présence, sauf si la puce y est déjà ; un départ la ferme ; un fait rejoué ne la touche pas deux fois ;
- **faits arrivés dans le désordre** (un lot renvoyé après un autre) : un départ plus ancien que l'arrivée connue ne ferme rien ;
  une arrivée plus ancienne qu'un départ déjà connu ne pose rien (un départ reçu seul est retenu pour cela) : pas de pièce fantôme ;
- **une ARRIVÉE à l'heure douteuse (`clockDoubt`) est gardée mais ne pose rien** : la présence sert à dire ce qui est posé avant un
  paiement, les heures sont celles du boîtier. Un DÉPART vaut toujours (à l'heure du serveur s'il est douteux) ;
- la pièce d'une présence est celle que sa puce porte au moment où l'on regarde ;
- une puce n'est que sur un tapis à la fois : arrivée sur un autre, sa présence sur le premier se ferme (règle tenue par le code) ;
- les présences ouvertes d'un tapis se ferment quand on ne sait plus ce qui est posé dessus : tapis noté en panne (annoncé `down`,
  plus annoncé, genre changé) ou retiré ; boîtier revenu d'un silence (fermées au dernier signe de vie), réinscrit, à la clé
  remise à zéro, révoqué ; horloge du boîtier qui se dérègle ; réponse d'un signe de vie perdue, ou boîtier redémarré ;
- **une fermeture décidée par le serveur est datée à l'horloge du BOÎTIER** (heure du serveur plus l'écart mesuré au dernier signe
  de vie ; écart inconnu ou douteux : la tolérance de 60 secondes est retirée ; jamais avant l'arrivée) : les arrivées sont datées
  par le boîtier, et ce qu'il redit juste après ne doit pas être jugé « plus ancien qu'un départ connu » parce qu'il retarde de
  quelques secondes (`LossBoxChannelService::boxTime`). Restent hors de portée : une horloge de boîtier qui recule d'un coup, et
  une puce qui passe d'un tapis à l'autre puis revient en moins que l'écart entre les horloges de deux boîtiers ;
- **un départ reçu seul vaut lecture** : le tapis a bien vu la puce. La pièce compte comme lue pendant la fenêtre de 10 minutes,
  elle n'est pas dite posée (ni `onMat`, ni `extra`), et le tapis n'est plus « en attente d'une première lecture ».
- À savoir : rien ne recalcule la présence depuis les faits, et la table n'est pas purgée (une ligne par pose) ; le lot L3-b2, qui
  s'en sert pour relier les ventes, réglera sa conservation.

**Routes de l'administrateur** (`/api/gestion/pertes`, `ROLE_ADMIN`, garde du module) :

| Route | Réponse | Refus |
|---|---|---|
| `PUT /boitiers/{id}/appareils/{appareil}` `{ cashRegisterId }` pour un tapis, `{ zone }` pour un lecteur (`null` délie) | la fiche du boîtier | 422 `fields` : `REQUIRED`, `MUST_BE_POSITIVE_INTEGER`, `INVALID_VALUE`, `NOT_FOR_THIS_DEVICE` (un poste pour un tapis, une zone pour un lecteur), `device: NOT_ASSIGNABLE` (imprimante, caméra) ; 404 `BOX_NOT_FOUND`, `DEVICE_NOT_FOUND`, `CASH_REGISTER_NOT_FOUND` (poste inconnu ou inactif) ; 409 `REGISTER_ALREADY_HAS_MAT { deviceId }` |
| `POST /boitiers/{id}/appareils/{appareil}/retirer` `{ confirm: true }` | la fiche du boîtier | 422 `fields.confirm` ; 404 |

- **Un tapis par poste, un poste par tapis** (deux liaisons au même instant : la seconde attend la première sur un verrou
  consultatif du poste, puis reçoit le 409 ; l'index unique de la base reste la ceinture, son 409 porte alors `deviceId: null`). Fait
  `DEVICE_ASSIGNED { cashRegisterId | zone }` ; relier au même poste n'écrit rien. Un appareil retiré, ou celui d'un boîtier
  révoqué, ne se relie plus (404 `DEVICE_NOT_FOUND`). **Révoquer un boîtier rend les postes de ses tapis.**
- **Retirer un appareil remplacé** : il sort de la fiche, du tableau de santé et des réglages du boîtier, ne compte plus dans le
  plafond des 64 appareils, ses faits reçoivent `DEVICE_UNKNOWN`, et il rend son poste de caisse pour de bon. Fait
  `DEVICE_RETIRED`. Si le boîtier l'annonce de nouveau, il revient (`DEVICE_SEEN { again: true }`), sans poste.
- La fiche d'un boîtier montre pour chaque appareil `cashRegister { id, name } | null` et, pour un tapis, `onMat` (le nombre de
  puces posées dessus en ce moment ; `null` pour les autres appareils).

**Tableau de santé** (§ 16.13) : une ligne par tapis en service, `code: mat-<id>`, `kind: mat`, `zone: counter`,
`cashRegisterId`, libellé « Tapis de comptoir · <nom du poste> ». États : `down` s'il est débranché (conseil : le câble, puis
l'alimentation), si son boîtier se tait (conseil : vérifier le boîtier) ou si l'horloge de son boîtier est déréglée (conseil :
l'accès du boîtier à Internet ; ce n'est pas un silence : `silentSince` et `silentSeconds` restent nuls) ; `pending` tant que
l'horloge d'un boîtier tout juste réinscrit n'est pas mesurée (prêt à son premier signe de vie), tant qu'il n'a lu aucune puce
(conseil : poser une pièce dessus), puis tant qu'il n'est relié à aucun poste (conseil : choisir son poste) ; sinon `ok`. Le tapis est
facultatif : aucune ligne tant qu'aucun n'est branché, et le bandeau ne s'alarme pas. La lecture fait maintenant trois requêtes.

**Assistant** : `optionalSteps` gagne `counter_mat` (« Brancher le tapis de comptoir »), faite quand un tapis a lu une puce et qu'il
est relié à son poste ; `state` et `hint` reprennent la pastille du tapis ; sans tapis : `absent`, « branchez le tapis sur le
boîtier ».

**Exercice** : le boîtier simulé a un tapis simulé (`SIM-TAPIS`, sans poste de caisse : lu, il est en ordre) et deux scénarios de
plus, `counter_put` (une pièce d'exercice est posée sur le tapis) et `counter_take` (elle le quitte) ; 409 `EXERCISE_NO_PIECE` sans
pièce d'exercice qui porte une puce. Rien de cela n'existe pour la caisse.

**La route de la caisse** : `POST /api/pos/pertes/articles-non-lus` (droit du caissier : `ROLE_USER_POS`, `ROLE_POS_MANAGER`,
`ROLE_ADMIN` ; en-tête de la tablette comme toute la caisse), appelée AVANT le paiement.

- Corps : `{ cashRegisterId?, items: [{ productVariantId, quantity }] }` (200 lignes au plus ; deux lignes de la même variante
  s'additionnent ; un article libre, sans variante, n'est pas envoyé).
- Réponse 200 : `{ active, reason, cashRegisterId, tracked, read, unread [{ productVariantId, name, variantLabel, quantity,
  missing }], extra, checkedAt }`. `tracked` : articles suivis du panier ; `read` : combien sont lus ; `unread` : ceux qui manquent,
  avec `missing` ; `extra` : le nombre de pièces ENCORE posées sur le tapis qui ne sont pas dans le panier (un nombre, sans dire
  lesquelles).
- **Un article est suivi** quand sa variante a au moins une vraie pièce en service qui porte une puce. **Une pièce est lue** si elle
  est sur le tapis de ce poste, ou l'a quitté depuis moins de 10 minutes (`LossCounterService::READ_WINDOW` ; réglable en L3-b2).
  Une pièce vendue ou retirée ne compte pas ; une puce retirée ne lit plus sa pièce.
- **Pas de contrôle plutôt qu'un faux avis** : `active: false`, listes vides, toujours 200, avec `reason` :
  - `MODULE_INACTIVE` : le site n'a pas le droit du module, quel que soit le mode de la plateforme (vérifié en premier : ni le
    poste ni le panier ne sont alors regardés) ;
  - `TERMINAL_NOT_BOUND` : sur un site à plusieurs postes, l'appel d'un caissier ne vient pas d'une tablette rattachée à ce poste ;
  - `NO_MAT` : ce poste n'a pas de tapis en service ;
  - `MAT_SILENT` : tapis en panne, boîtier silencieux depuis plus longtemps que le délai du site, ou horloge du boîtier déréglée
    (ses arrivées ne posent alors rien : dire « non lu » serait un faux avis) ;
  - `TOO_MANY_REQUESTS` : plus de 6 000 LIGNES de panier en 5 minutes pour ce compte (une question compte pour ses lignes, au
    moins une ; une question par paiement suffit très au large ; la route ne sert pas à sonder le catalogue). Si le compteur
    lui-même est en panne, la question passe.
- **Un caissier ne lit que le tapis de SON poste** (consigne du chef de projet). Le poste se résout comme pour une vente
  (`CashRegisterResolver`) et doit avoir une session ouverte ; refus de la caisse, à la forme de la caisse : 404
  `CASH_REGISTER_NOT_FOUND`, 400 `CASH_REGISTER_REQUIRED`, 409 `CASH_REGISTER_CLOSED`, 403 `CASH_REGISTER_FORBIDDEN` (tablette
  rattachée à un autre poste), 422 `VALIDATION_FAILED { errors [{ path, message }] }`. **En plus**, pour un caissier (ni
  responsable ni administrateur) : sa tablette doit être rattachée à CE poste et envoyer son en-tête ; sinon, sur un site à
  plusieurs postes, pas de contrôle (`TERMINAL_NOT_BOUND`) : on ne sait pas quel poste est le sien. Sur un site à un seul poste, la
  question ne se pose pas. Le responsable et l'administrateur lisent tous les postes, comme à la caisse. Seul le tapis du poste
  résolu est lu ; les données sont celles du site de la requête.
- **À savoir** : en envoyant des paniers au hasard, un caissier peut apprendre quels articles sont suivis (c'est ce que la route
  lui dit de son panier) ; le plafond, compté en lignes, borne la chose à 6 000 variantes par 5 minutes, et le catalogue lui est
  déjà ouvert. Sur un site qui gagne un second poste, le contrôle s'arrête pour toute tablette non rattachée
  (`TERMINAL_NOT_BOUND`) : rien ne le dit encore à l'administrateur (à montrer par le web ou la caisse ; étape d'assistant à voir
  en L3-b2).
- **Une lecture : le module n'écrit rien** (ni fait, ni constat, ni journal du module, ni journal de caisse). Comme sur toute route
  de caisse, la garde de la tablette note son dernier usage dans les clés d'activation, au plus une fois par 5 minutes.
- **Pour la caisse, à voir à sa reprise** : un indicateur dans ses réglages (`/api/pos/settings`) lui éviterait l'appel sur un site
  sans le module ; d'ici là l'appel coûte une lecture. **Elle ne bloque jamais la
  vente** : la caisse affiche un avis, le caissier pose l'article ou continue ; la caisse traite toute réponse autre que 200, et
  tout retard, comme « pas de contrôle » ; hors ligne, elle n'appelle pas.
- **Ordre des migrations** : celle de ce lot REMPLACE trois fonctions de garde du lot L3-a. Rejouer le script de L3-a
  (`migrate_all_v2_pertes_l3a.sh`) sur une base qui porte déjà L3-b1 remettrait les anciennes : le script s'y refuse désormais, et
  le test de la migration de L3-a rejoue la sienne dans une transaction annulée.
- **Ce que la base ne garde pas** (le code le tient) : une présence peut être supprimée ou rouverte en SQL direct, une puce
  ouverte sur deux tapis, un appareil retiré relié à un poste ; `cash_register_id` n'a pas de clé étrangère.
- **Relecture indépendante** (16/11/2026) : la route n'écrit rien et ne peut pas bloquer une vente ; corrigés : un caissier sans
  tablette rattachée lisait le tapis de n'importe quel poste ouvert ; un boîtier à l'horloge déréglée donnait un faux avis à
  chaque vente ; présences jamais fermées après une réinscription ; boîtier révoqué qui gardait le poste de ses tapis ; le serveur
  fermait des présences sans que le boîtier le sache (`resendMats`) ; faits dans le désordre ; appareil retiré qu'on pouvait
  relier ; liaisons simultanées ; lecture des appareils avant le verrou ; plafond des questions. **Seconde passe** : aucun chemin
  par lequel un caissier lit le tapis d'un autre poste ; corrigés : deux liaisons simultanées rendaient encore 500 (verrou du
  poste) ; un `resendMats` perdu avec sa réponse n'était jamais redit (`lastServerTime`) ; ce qu'un boîtier en retard redisait
  après une fermeture du serveur n'était pas posé (fermetures datées à l'horloge du boîtier) ; le plafond comptait les questions,
  pas les lignes ; pastille « horloge » qui annonçait un silence, ou une panne juste après une réinscription.
- **Tests** : `LossPreventionCounterTest` (tapis lu, relié, retiré, pastille, étape, horloge, désordre, réinscription, révocation ;
  la question de la caisse, poste par poste, sans rien écrire ; tapis simulé ; gardes de la base et rejeu de la migration).

### 16.16 Lot L3-a2 : jumelage du boîtier par code (décision du propriétaire, 16/11/2026 ; plan ci-dessous, contrat codé au § 16.17)

On ne tape plus la clé dans le boîtier : **c'est le boîtier qu'on déclare dans l'application**, comme un appareil de maison
connectée. La clé `IZ-BOX` (§ 16.13) reste, en secours, pour les installateurs avancés. **Contrat validé par le chef de projet le
16/11/2026** (une table dans la base maître acceptée ; remise à zéro du jumelage et détachement acceptés) ; à coder après L3-b1.

- **Le code de jumelage** : court et lisible, trois groupes de trois caractères (exemple `KX7-42P-9QM` ; alphabet sans caractères
  ambigus, 45 bits), avec son code QR (le QR porte le code, rien d'autre). **C'est le boîtier qui le crée**, avec un secret de
  machine qu'il garde pour lui : à la préparation pour un boîtier vendu (le code est imprimé sur son étiquette), par la commande
  d'installation pour le logiciel seul (elle l'affiche dans le terminal).
- **Le boîtier attend son jumelage** : branché, il appelle une route publique de la plateforme (appel sortant, aucun port ouvert,
  aucun site encore) et y dépose sa demande : l'empreinte de son code et une **preuve liée à sa machine**. Il revient demander où
  il en est, toujours avec cette preuve : **le code seul ne donne rien**. Une demande non jumelée dans les 30 jours est oubliée.
- **L'administrateur jumelle** : dans la gestion, « Ajouter un boîtier », il scanne le code QR (mobile) ou tape le code (web).
  Seul un administrateur connecté le fait, et le boîtier est relié à SON site (celui de sa session), jamais à un autre. Le boîtier
  reçoit son jeton à son appel suivant ; sa pastille passe au vert.
- **Le code ne sert qu'une fois.** Essais plafonnés par site et par adresse ; **refus neutre** : un code inconnu, expiré ou déjà
  servi rendent la même réponse.
- **Où cela vit** : la demande d'un boîtier qui n'a pas encore de site ne peut pas être rangée dans la base d'un site. Elle va dans
  une table de la base maître (la seule table du module hors des bases de site, comme les clés d'activation des applications) ; le
  boîtier, lui, reste une ligne de `loss_box` dans la base du site, comme aujourd'hui.
- **Points à donner en premier au relecteur neuf** : personne ne peut jumeler un boîtier à un autre site que le sien ; un code volé
  sur l'étiquette ne permet pas de détourner un boîtier déjà jumelé ; aucun code ne se devine par force brute.
- **Risque qui reste, à dire** : celui qui lit l'étiquette AVANT le commerçant (pendant la livraison) et qui administre un autre site
  de la plateforme peut jumeler le boîtier chez lui. Le commerçant le voit aussitôt (son code est refusé) ; parade : remettre le
  jumelage à zéro sur le boîtier (nouveau code), et l'autre site ne reçoit que des lectures de puces inconnues de lui.
- **Remettre le jumelage à zéro, sur le boîtier** : une commande, et un bouton sur le boîtier vendu, créent un nouveau code (et un
  nouveau secret de machine) ; l'ancien code ne vaut plus rien. **Détacher, dans l'application** : un administrateur qui a jumelé
  chez lui un boîtier par erreur le détache (c'est une révocation du boîtier sur son site) ; la machine, refusée à son appel
  suivant, remet son jumelage à zéro et redevient jumelable ailleurs. Les deux gestes sont à écrire dans le guide.
- **Routes prévues** : boîtier, sans site ni jeton : `POST /api/pertes/boitier/jumelage { code, machine, version, arch }` (dépôt :
  `{ status: waiting, expiresAt }` ; la première demande déposée pour un code gagne) et `POST /api/pertes/boitier/jumelage/etat
  { code, machine }` (`waiting`, ou `{ status: paired, host, box { id }, token, expiresAt, settings }`) ; administrateur :
  `POST /api/gestion/pertes/boitiers/jumeler { code, name, place? }` → 201 la fiche (en attente de sa machine, puis inscrite à
  l'appel suivant du boîtier) ; 404 `PAIRING_CODE_INVALID` neutre ; 429 au-delà des plafonds (10 essais par site et par adresse
  et par quart d'heure).
- **À la bascule** : « appli degestion backend » pose la table des demandes de jumelage sur la base maître (fichier SQL fourni
  avec le lot, et sa ligne dans l'amorce des tests).
- **Emballage** (réponse du propriétaire, 16/11/2026) : l'étiquette du code est sur le boîtier, À L'INTÉRIEUR de l'emballage
  scellé, jamais sur le carton ; pas de volet à gratter. À écrire dans le guide.
- **Pour L6, sans code aujourd'hui** (décisions du propriétaire, 16/11/2026) :
  - « Renvoyer le code par courriel », depuis la gestion, pour un boîtier VENDU : le code part à l'administrateur du site
    acheteur, jamais à une autre adresse. Cela dépend de la vente du boîtier, donc de L6 ;
  - à l'expédition, le numéro de série d'un boîtier vendu est relié au site acheteur. Au premier branchement, la plateforme le
    reconnaît ; l'administrateur reçoit un courriel et une notification « Votre boîtier est branché », confirme d'un clic « C'est
    bien mon boîtier », et la pastille passe au vert. Aucun code à saisir ; le code reste en secours.
- **Dans le dépôt du boîtier** (à sa création) : le guide de l'installateur commence par « branchez, puis scannez l'étiquette ».

### 16.17 Lot L3-a2 codé : jumelage du boîtier par code (16/11/2026)

Code : `Services/LossPairingService` (dépôt, état, jumelage), `Services/LossPairingCode` (forme du code),
`Master/LossPairingRepository` (base maître), `LossBoxChannelService::enrolPaired` (inscription de la machine d'un boîtier jumelé),
`LossBoxService::createPaired`. **Une table dans la base maître**, `loss_box_pairing` (`ops/sql/loss_pairing_master.sql`, relu par
`tests/bootstrap.php`) : la seule du module hors des bases de site. **Aucune migration de site.** La clé `IZ-BOX` (§ 16.13) reste,
en secours.

**Le boîtier, sans site ni jeton** (à l'adresse de la plateforme ; aucun site n'est résolu pour ces deux routes, l'en-tête
`X-Tenant-Host` est ignoré) :

| Route | Réponse | Refus |
|---|---|---|
| `POST /api/pertes/boitier/jumelage` `{ code, machine, version, arch }` | `{ status: waiting \| paired \| closed, expiresAt, pollSeconds, serverTime }` (jamais de jeton ici) | 422 `VALIDATION_FAILED { fields }` ; 404 `PAIRING_CODE_INVALID` (code déjà déposé par une autre machine) ; 429 `RATE_LIMITED { retryAfterSeconds }` |
| `POST /api/pertes/boitier/jumelage/etat` `{ code, machine }` | en attente ou fermé : comme ci-dessus ; jumelé : `{ status: paired, host, box { id }, token, expiresAt, serverTime, settings }` (la réponse d'une inscription, § 16.13, plus `host`) | 422 ; 404 `PAIRING_CODE_INVALID` (code inconnu OU mauvaise machine : même réponse) ; 429 |

- **Le code** : `KX7-42P-9QM`, 9 caractères de l'alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (45 bits), créé par le boîtier ; saisie
  tolérante (minuscules, espaces, tirets). **`machine`** : le secret de machine du boîtier (16 à 128 caractères), sa preuve à chaque
  appel. La base ne garde que deux empreintes liées au secret de l'application ; ni le code ni la machine n'y sont en clair.
- **Le boîtier dépose AVANT de montrer son code** (étiquette imprimée à la préparation, terminal de la commande d'installation) :
  la première machine qui dépose un code le garde, et aucune autre ne peut le déposer ensuite. C'est ce qui rend l'étiquette
  inutile à qui la lit en chemin.
- **`waiting`** : la demande est jumelable pendant 30 jours après le dernier signe du boîtier (`expiresAt`) ; chaque question du
  boîtier la fait revivre (écrit au plus une fois par demi-journée). Expirée, elle n'est plus jumelable mais **le code reste au
  boîtier** : un boîtier resté des mois ou des années dans son carton redevient jumelable dès qu'il est branché. **Une demande
  n'est jamais supprimée par le code** : tant qu'elle existe, aucune autre machine ne dépose son code (la relecture a montré qu'un
  « oubli » au bout d'un an rendait le code à qui avait lu l'étiquette). Le ménage de cette table, s'il en faut un jour, est une
  décision de plateforme (L6), avec cette règle : jamais la demande d'un boîtier vendu.
- **`paired`** : le boîtier, sur sa preuve, reçoit l'adresse de son site (`host`) et son jeton : il est inscrit à cet appel (fait
  `BOX_ENROLLED { pairing: true }`), puis parle à son site comme tout boîtier. Si la réponse se perd, il redemande : un jeton
  neuf lui est remis, l'ancien ne vaut plus rien. Il cesse de demander dès qu'il a son jeton ; si son jeton expire au-delà du délai
  de grâce, il redemande ici.
- **`closed`** : le boîtier a été détaché (révoqué sur son site), sa clé a été remise à zéro par l'administrateur, ou une autre
  machine s'y est inscrite par la clé. Le code ne vaut plus rien, pour toujours : le boîtier remet son jumelage à zéro (nouveau
  code, nouveau secret de machine) et redevient jumelable.
- **`pollSeconds`** (15) : le rythme conseillé des questions. **Sans le module** sur le site jumelé, ou si ce site est introuvable
  (renommé, supprimé), ou si la base maître ne répond pas, rien n'est remis et rien n'est fermé : `waiting`, `expiresAt: null` ;
  les deux cas sont écrits au journal du serveur, chacun sous son nom.
- **Un jumelage ne se ferme que sur une preuve sûre** (correctif du 16/11/2026, relevé par la relecture du logiciel du boîtier) :
  - le site d'un jumelage relié est relu **dans la base maître, sans cache** (`LossPairingRepository::tenant`), et le boîtier est
    lu **par son numéro** (`box_id` de la demande) dans la base de ce site ;
  - on vérifie d'abord que le boîtier lu est bien **celui que ce jumelage a créé** : l'empreinte de sa clé est celle du jumelage,
    ou, si sa clé a été remise à zéro depuis, son fait de création porte la **marque du jumelage**
    (`BOX_CREATED { pairing: true, ref }`, `LossBoxToken::pairingRef` ; un fait ne se modifie jamais) ;
  - sur ce boîtier-là, trois preuves ferment : il est révoqué ; sa clé a été remise à zéro ; une autre machine y est inscrite
    (`LossPairingService::CLOSING`, liste fermée : toute autre raison fait patienter) ;
  - **un boîtier introuvable, d'exercice, ou qui n'est pas celui du jumelage ne ferme rien**, même révoqué : c'est ce qu'on lirait
    dans une base qui n'est pas celle du site (fiche de site fausse, base remplacée par la copie d'un autre site), où un boîtier
    du même numéro existe presque toujours. Le boîtier patiente (`waiting`, `expiresAt: null`) ; comme chaque question compte
    pour une remise, il reçoit `429` après dix questions par quart d'heure ;
  - le serveur l'écrit à son journal, **une ligne au plus par jumelage et par heure** (plafond `pairWarning`) : « jumelage N laissé
    tel quel », avec le numéro du boîtier et le code du site, jamais le code du jumelage. Même ligne quand le site est introuvable
    dans la base maître, ou que la base maître ne répond pas (le code SQL de l'erreur seulement).
  - **Ce que l'exploitant fait de cette ligne.** Si la base du site est la mauvaise (ligne `tenants` fausse), il la corrige : le
    boîtier est servi à sa question suivante. Si la base du site a été reprise d'une sauvegarde plus ancienne que le jumelage, le
    boîtier n'existe plus et ne reviendra pas : l'exploitant ferme la demande à la main dans la base maître
    (`UPDATE loss_box_pairing SET status = 'closed', closed_at = NOW() AT TIME ZONE 'UTC' WHERE id = N`) ; le boîtier reçoit
    `closed`, tire un nouveau code, et l'administrateur le jumelle de nouveau.
  - Un boîtier jumelé **avant ce correctif** n'a pas la marque dans son fait de création : tant que sa clé n'est pas remise à
    zéro, rien ne change pour lui (son empreinte le prouve) ; après une remise à zéro, son jumelage n'est plus fermé d'office, le
    boîtier patiente et l'installateur le relie par sa nouvelle clé. Aucun vrai boîtier n'était jumelé à cette date.
  - Avant ce correctif, une fiche de site fausse dans le cache menait dans la base d'un autre site, où le boîtier était
    « introuvable », et fermait pour toujours un jumelage sain : le boîtier devait être rejumelé à la main.

**L'administrateur** (`ROLE_ADMIN`, garde du module) : `POST /api/gestion/pertes/boitiers/jumeler` `{ code, name, place? }` → 201
`{ box }`, la fiche du boîtier, `status: pending` (il attend sa machine), `paired: true`, `keyHint: null`. Refus : 422
`fields.name | place` ; 404 `PAIRING_CODE_INVALID` ; 409 `BOX_LIMIT_REACHED { limit }` (le code n'est pas consommé) ; 429
`RATE_LIMITED { retryAfterSeconds }` avec `Retry-After`.

- **Le site est celui de la session**, jamais un champ de la requête : personne ne jumelle un boîtier à un autre site que le sien.
- **Refus neutre** : un code inconnu, mal formé, expiré, déjà jumelé (ici ou ailleurs) ou fermé reçoivent la même réponse, mot
  pour mot : « Code inconnu, ou boîtier pas encore branché à Internet. Branchez le boîtier, attendez que son voyant clignote, puis
  réessayez. » **Le code ne sert qu'une fois.**
- Toute fiche de boîtier gagne `paired` (vrai pour un boîtier ajouté par code tant que personne ne lui a donné de clé). Fait
  `BOX_CREATED { pairing: true, ref }` (`ref` : la marque du jumelage, qu'aucune lecture ne rend). **« Détacher »** est la révocation du boîtier (`POST /boitiers/{id}/revoquer`).
- **Ce qui lie le boîtier du site à son code** : à la place de l'empreinte d'une clé, le boîtier jumelé porte une empreinte liée
  AU SITE, au code, et à un aléa tiré à chaque jumelage et écrit sur la demande (`link` ; `LossBoxToken::pairingKeyHash`). À la
  remise du jeton, le boîtier est lu par le numéro écrit sur la demande par le jumelage (`box_id`), dans le site écrit sur la
  demande, puis **reconnu par cette empreinte** (ou, clé remise à zéro, par la marque de son fait de création) : aucun
  identifiant venu d'une requête ne désigne un boîtier ni un site, et un numéro seul ne décide de rien. Ce n'est pas une clé
  `IZ-BOX` : la route d'inscription ne l'accepte pas.

**Plafonds** (`LossBoxLimits`) : dépôts, 20 par adresse et par quart d'heure ; **demandes nouvelles** (un code que la
plateforme ne connaît pas : une ligne de la base maître qui ne s'efface pas, et n'importe qui peut en déposer), **10 par adresse et
par heure, et 300 par heure pour toute la plateforme** (`LossPairingService::NEW_PER_HOUR`, compté dans la base sous verrou
consultatif : ce plafond-là est exact, il ne dépend ni du cache ni des appels simultanés ; au-delà, un boîtier NEUF ne dépose plus,
les boîtiers déjà déposés ne sont pas touchés) ; codes inconnus et mauvaises preuves du
boîtier, 30 par adresse et par quart d'heure, lus AVANT toute lecture (l'adresse est alors arrêtée, même avec la bonne preuve) ;
questions d'un boîtier qui a fait sa preuve, 120 par demande et par 5 minutes ; jetons remis à un boîtier jumelé, 10 par demande et
par quart d'heure (chaque remise est une inscription, avec son fait) ; essais d'un administrateur, 10 par site et adresse et 30 par
site, par quart d'heure (au-delà, même le bon code est refusé). Hors celui de la plateforme, ces compteurs vivent dans le cache
et ne sont pas verrouillés : des requêtes simultanées peuvent les dépasser, d'autant plus qu'elles sont nombreuses ; c'est la
taille du code (45 bits) qui tient la garantie, les plafonds la confortent. Avant son jumelage un boîtier n'a que son adresse pour le désigner (comme
à l'inscription par clé) ; une fois jumelé il parle avec son jeton, que l'adresse ne limite jamais.

**Les trois points donnés au relecteur** : (1) le site d'un jumelage n'est lu que de la session de l'administrateur ; (2) un code
volé ne détourne rien : sans la preuve de la machine il ne remet aucun jeton, un code jumelé n'est plus jumelable, un code déposé
n'est repris par aucune autre machine ; (3) 45 bits, refus neutre, essais plafonnés par adresse ET par site.

**Risques qui restent, à dire** :
- celui qui lit l'étiquette AVANT le commerçant et administre un autre site de la plateforme peut jumeler le boîtier chez lui. Le
  commerçant le voit aussitôt (son code est refusé) ; parade : remettre le jumelage à zéro sur le boîtier. L'autre site ne reçoit
  que des lectures de puces qu'il ne connaît pas ;
- le jumelage écrit dans deux bases (la base maître et celle du site) sans transaction commune : si la base maître tombe juste
  après la création du boîtier, il reste un boîtier « en attente » que rien ne désigne. L'administrateur le révoque et rejumelle le
  MÊME code : un autre boîtier est créé (aléa neuf), et c'est lui que la machine reçoit ;
- si la réponse d'un jumelage réussi se perd, un second essai reçoit le refus neutre (le code a servi) : le boîtier est bien
  dans la liste du site, le web peut la relire ;
- quelqu'un qui inonde la plateforme de demandes inventées, depuis des dizaines d'adresses (10 par adresse et par heure), atteint
  le plafond de la plateforme : **tant que ce bruit dure**, un boîtier NEUF ne peut pas déposer sa demande (ni un logiciel seul,
  ni une préparation ; la clé `IZ-BOX` reste en secours) ; les boîtiers déjà déposés ou jumelés ne sont pas touchés. La table
  grossit d'au plus 300 lignes par heure. À surveiller (nombre de demandes en attente) ; à terme (L6), une preuve de fabrication
  dispensera les boîtiers vendus de ce plafond. Une préparation de plus de 10 boîtiers par heure depuis la même adresse attend
  l'heure suivante : à relever en L6 avec la préparation ;
- **si le secret de l'application change**, toutes les empreintes (codes, machines, boîtiers jumelés) deviennent orphelines,
  comme les clés `IZ-BOX` (§ 16.13) : chaque boîtier repart d'un jumelage neuf ;
- le serveur ne peut pas vérifier que le boîtier a déposé AVANT de montrer son code : c'est une règle du logiciel du boîtier et
  de la préparation, à écrire dans leur guide et à tester là-bas ;
- du bruit envoyé depuis l'adresse de la boutique (30 mauvais codes en un quart d'heure) retarde le jumelage d'un boîtier de cette
  adresse d'un quart d'heure ; il ne touche aucun boîtier déjà jumelé.

**À la bascule** : passer `ops/sql/loss_pairing_master.sql` sur la base maître AVANT l'échange du code (idempotent), puis
`cache:clear` (deux routes, un service, deux chemins de plus dans la liste des routes sans site du `TenantDoctrineSwitcherListener`).
Vérifier que l'adresse de la plateforme que les boîtiers appelleront passe par un relais connu de `TRUSTED_PROXIES` (sinon tous
les boîtiers partagent une adresse, et les plafonds par adresse deviennent communs). Le canal du boîtier ne compte plus comme une
visite du site (`TenantVisitorTrackingListener` : préfixe `/api/pertes/boitier` exclu).

**Relecture indépendante** (16/11/2026) : les trois points sont sains ; corrigés : une demande « oubliée » au bout d'un an rendait
son code à une autre machine (plus aucune suppression) ; écriture anonyme sans plafond global dans la base maître (plafond des
demandes nouvelles) ; après un boîtier orphelin, le même code rendait 500 sur ce site (aléa par jumelage) ; une panne d'un instant
de la lecture du site fermait le jumelage pour toujours (le boîtier patiente) ; transaction de la base maître tenue sur une seule
connexion, et jumelage refusé si la demande n'a pas été écrite ; remises de jeton plafonnées. **Seconde passe** : tout est
corrigé, les trois points sont sains ; le plafond global posé à la première passe se remplissait depuis deux adresses et n'était
pas exact : il est maintenant précédé d'un plafond par adresse et compté dans la base sous verrou. Le fichier SQL n'a été passé
sur aucune base avant sa version finale (il ne met pas à niveau une version antérieure de la table).

**Tests** : `LossPreventionPairingTest` (le parcours entier ; refus neutres mot pour mot ; un administrateur d'un second site ; code
seul sans preuve ; jeton refusé sur un autre site ; réponse perdue ; détacher, clé remise à zéro, module retiré, site introuvable ;
boîtier orphelin rejumelé ; plafond de boîtiers ; expiration ; code jamais libéré ; chemin encodé ; les six plafonds ;
`testAPairingIsNeverClosedOnAStaleSiteRecordOrAMissingBox` : fiche de site fausse dans le cache, boîtier introuvable ou d'exercice,
numéro d'un autre boîtier (en service, révoqué, ou d'un autre jumelage à la clé remise à zéro), clé remise à zéro puis révocation,
site disparu, une seule ligne au journal par heure). Exemples : `tests/Fixtures/pertes/boitier/canal-jumelage-*.json`,
`admin-boitier-jumele*.json`, `admin-refus-jumelage.json`.

### 16.18 Lot L3-b2a codé : la vente reliée aux pièces (16/11/2026)

L3-b2 est coupé en deux (validé par le chef de projet) : **L3-b2a**, ci-dessous, et **L3-b2b** (la règle « articles suivis vendus
sans lecture », par poste et par jour, et les scénarios d'exercice). Code : `Services/LossSaleLinkService` (une étape de l'analyse
à curseur, § 3), `Repository/LossSaleUnitRepository`, `Entity/LossSaleUnit`. Migration `Version20270116100000` (script
`scripts/migrate_all_v2_pertes_l3b2a.sh`) : table `loss_sale_unit`, sa garde, trois colonnes de réglage ; **aucune fonction d'un
lot précédent n'est remplacée, aucune table d'un lot précédent ne reçoit d'index** (un ancien test de migration énumère tous
ceux de `loss_piece_event`).

**Ce que le lot ne fait pas** : aucun appel du module pendant une vente, aucune vente bloquée, **aucune écriture dans une table de
la caisse** (il lit `pos_audit_log`, `"order"`, `order_items`) ; rien pour un article non suivi ni pour un article libre ; aucun
constat (le constat « sortie sans vente » est L4) ; aucun nom de personne lu ou écrit.

**Quand** : à chaque passage de l'analyse (toutes les 15 minutes, et à la demande : `POST /api/gestion/pertes/analyse`), donc
jamais sans le droit du module, ni avant la notice aux salariés là où elle est exigée (§ 3). Les entrées `SALE` et `REFUND` du
journal de caisse sont maintenant toujours lues, quelles que soient les règles allumées.

**Une vente** (`SALE`) :
- pour chaque **article suivi** vendu, une ligne `loss_sale_unit` : la commande, la ligne de la vente, son rang dans la ligne (une
  ligne de 3 articles donne 3 lignes), la variante, le poste, l'heure, l'entrée du journal ;
- **suivi à l'heure de la vente** : la variante avait alors une vraie pièce qui portait une puce, ni l'une ni l'autre retirée. Un
  article dont la première puce est posée après la vente n'est pas compté pour elle ;
- **l'heure est l'heure RÉELLE de la vente** : celle de la commande (`"order".order_date`, en UTC pour une vente en ligne) ou, pour
  une **vente hors ligne** synchronisée plus tard, l'instant que la caisse a écrit dans le journal (`data.offline.soldAt`, avec
  son décalage horaire, converti en UTC : la colonne de la commande garde alors l'heure du magasin). La vente est reliée à ce qui
  était posé quand elle a eu lieu, pas à l'heure de la synchronisation ;
- **une ligne de location n'est pas une vente** (elle porte une réservation) : elle n'est pas lue, sa pièce ne devient pas `sold` ;
- **suivi veut dire la même chose qu'à la caisse** : un article dont TOUTES les pièces à puce sont déjà vendues n'est plus suivi ;
  le réassort vendu avant d'être étiqueté n'est pas compté ;
- une pièce de cette variante **lue sur le tapis de CE poste** (posée dessus, ou partie depuis moins que la fenêtre du tapis avant
  la vente) lui est reliée : fait `PIECE_SOLD { by: counter, orderId, orderItemId, cashRegisterId, soldAt }`, pièce au statut
  `sold`. La pièce posée depuis le plus longtemps d'abord ; **une pièce par article, un article par pièce** (deux index uniques).
  Le tapis d'un autre poste ne compte pas, ni une puce retirée ;
- **une pièce rendue par un RETOUR d'articles, restée sur le tapis depuis avant son retour, n'est pas prise pour la vente
  suivante** (échange du même article : c'est la pièce neuve qui sort) : il faut la reposer. Le geste attendu au comptoir : poser
  la pièce rendue AVANT de valider le retour. **Une vente annulée puis ressaisie, elle, retrouve sa pièce** : une annulation n'a
  pas fait sortir la pièce (`returned_by` dit par quoi un article est revenu : `return` ou `cancellation`) ;
- la puce lue portait déjà cette pièce à l'heure de la vente (une puce qui traînait sur le tapis, posée sur une pièce après la
  vente, ne relie rien) ;
- un article suivi vendu sans pièce lue reste « à rattraper ».

**Le rattrapage par le tapis**, à chaque passage : les faits du boîtier peuvent arriver APRÈS la vente (boîtier revenu d'une
coupure, vente hors ligne). Pour un article écrit depuis moins de deux heures et encore sans pièce, le lien par le tapis de son
poste est retenté, avec la même fenêtre autour de l'heure de la vente (500 articles au plus par passage, comptés à part de ceux
qui attendent la sortie).

**Le rattrapage à la sortie**, à chaque passage : une vraie pièce ni vendue ni retirée, lue à la **sortie** (`ZONE_SEEN`, zone
`exit`, heure sûre) entre 2 minutes avant et la fenêtre du rattrapage après une vente de SA variante encore sans pièce, lui est
reliée : `PIECE_SOLD { by: catch_up, readEventId, … }`. **La vente la plus ancienne d'abord, la première lecture d'abord, une
pièce par article.** Ne comptent pas : une lecture par une puce retirée, et **une lecture d'AVANT le dernier retour d'articles de
la pièce** (la sortie du client qui l'a rapportée depuis ne vaut pas pour une autre vente). Une seule requête par passage, pour
toutes les variantes : elle ne rend que les lectures qui tombent dans la fenêtre d'au moins un article en attente (5 000 au plus),
si bien qu'un portant près de la porte, relu sans cesse, n'empêche pas les ventes du jour d'être rattrapées. Dans les deux sens : que la vente soit lue après la lecture, ou la lecture après la vente. Tous les postes
du site sont regardés (la sortie est commune) ; rien ne sort du site (la base est la sienne, la table n'a pas de colonne de site).
Le rattrapage regarde les ventes des 48 dernières heures.

**Un retour** (`REFUND`, `kind: return`, avec ses lignes) : pour chaque ligne, l'article de la ligne d'origine revient
(`returned_at`), dans cet ordre : celui dont la pièce est **relue sur le tapis du poste** du retour, puis un article sans pièce,
puis le premier de la ligne. Sa pièce redevient vendable : `PIECE_RETURNED { by: return, readOnCounter, orderItemId, sequence }`,
statut `returned`. Si la pièce n'a pas été relue, le fait le dit (`readOnCounter: false`) : quand un client a acheté deux fois le
même article et n'en rend qu'un, la pièce dite revenue peut ne pas être celle qui est revenue. **Une vente annulée**
(`kind: cancellation`) : tous ses articles reviennent (`by: cancellation`). Un retour de l'ancien chemin (sans ligne d'origine)
n'écrit rien. Un échange est un retour puis une vente : les deux sont lus.

**Ce que la base garantit elle-même** : un article vendu n'a qu'une ligne ; une pièce n'est reliée qu'à un article non revenu à
la fois ; la pièce est une vraie pièce de la variante vendue ; la pièce d'un article ne change pas et ne se retire pas ; un article
revenu ne change plus ; ce qui décrit la vente ne change pas ; aucune ligne ne se supprime.

**Réglages** (`GET | PUT /api/gestion/pertes/reglages`, `ROLE_ADMIN`) : la réponse gagne `pieces { counterWindowMinutes,
catchUpMinutes, presenceRetentionDays, bounds, defaults, note }` ; `PUT { pieces: { … } }` change les valeurs envoyées (`null`
remet la valeur par défaut ; 422 `MUST_BE_INTEGER`, `PARAM_OUT_OF_RANGE`, `pieces: MUST_BE_OBJECT`).

| Réglage | Défaut | Bornes | Effet |
|---|---|---|---|
| `counterWindowMinutes` | 10 | 1 à 60 | fenêtre du tapis, pour relier une vente ET pour la route de la caisse « articles non lus » (§ 16.15) |
| `catchUpMinutes` | 30 | 5 à 120 | fenêtre du rattrapage à la sortie, après la vente |
| `presenceRetentionDays` | 90 | 30 à 730 | conservation des présences fermées |

**Conservation** (accord du propriétaire, 16/11/2026) : les présences sur le tapis **fermées depuis plus de 90 jours sont effacées
automatiquement**, à chaque passage de l'analyse (5 000 au plus par passage). Une présence ouverte n'est jamais effacée ; **les
faits (lectures, ventes, retours) restent**. `note` le dit à l'administrateur, avec la durée en vigueur.

**Fiche d'une pièce** (`GET /api/gestion/pertes/pieces/{id}`) : `sale { orderId, reference, cashRegisterId, soldAt, by, byLabel,
linkedAt } | null` (la vente en cours de la pièce : « Lue sur le tapis du comptoir » ou « Rattrapée à la sortie ») ; les faits
`PIECE_SOLD` (« Vendue ») et `PIECE_RETURNED` (« Revenue en magasin ») sont dans `events`.

**Postes de caisse, pour l'administrateur** (demande du chef de projet, 16/11/2026) : `GET /api/gestion/pertes/postes`
(`ROLE_ADMIN`, garde du module ; sous la gestion, donc **hors de la garde de la tablette** : la route de la caisse
`GET /api/cash-registers` répond `TERMINAL_REQUIRED` à l'administration du web sur un site qui exige le terminal, et
l'administrateur ne pouvait relier aucun tapis). Réponse : `{ items [{ id, name, active, matDeviceId, terminalBound }],
terminalNeeded }` : tous les postes du site, actifs ou non ; `matDeviceId`, le tapis en service relié à ce poste ou `null` ;
`terminalBound`, une tablette de caisse en service est rattachée à ce poste ; `terminalNeeded`, le site a plusieurs postes actifs
(c'est alors qu'un caissier a besoin d'une tablette rattachée, et que le tableau de santé avertit). Une lecture ; pas de jumeau
d'exercice (le tapis simulé n'a pas de poste).

**Tableau de santé** (validé par le chef de projet) : sur un site à plusieurs postes, un tapis dont le poste n'a **aucune tablette
de caisse rattachée** (clé d'activation `pos` en service) est `pending`, avec le conseil « Aucune tablette de caisse n'est
rattachée au poste « X » : le contrôle avant paiement est arrêté pour les caissiers de ce poste… ». C'est la règle de L3-b1
(`TERMINAL_NOT_BOUND`) rendue visible.

**À savoir** :
- les faits `PIECE_SOLD` sont datés de l'heure de la VENTE, `PIECE_RETURNED` de celle du retour ;
- « suivi à l'heure de la vente » est jugé avec les liens connus quand la vente est lue : une pièce rattrapée plus tard pour une
  vente antérieure ne change pas ce qui a été écrit entre-temps ;
- l'heure d'un retour est celle du serveur, celle d'une pose ou d'une lecture est celle du boîtier (60 secondes d'écart tolérées) :
  une pièce reposée dans la minute qui suit son retour, par un boîtier en retard, peut être écartée ; il suffit de la reposer ;
- si l'analyse reste arrêtée plus longtemps que la conservation des présences, son premier passage efface les présences anciennes
  avant que les suivants lisent les ventes de la même époque (elles restent rattrapables à la sortie) ;
- les pièces `sold` comptent encore parmi les pièces « actives » des totaux de l'écran des pièces, et son filtre ne connaît que
  `created` et `retired` : à reprendre avec l'écran (L3-b2b) ; le message de la commande d'analyse parle de « règle en échec »
  pour toute entrée sautée, y compris par cette étape ;
- une vente de caisse annulée par un chemin qui n'écrit pas de `REFUND` au journal de caisse, ou un retour lui-même annulé, ne
  sont pas vus : la pièce garde son statut (à corriger à la main en retirant la pièce, ou à traiter quand un tel chemin existera) ;
- le rattrapage relit à chaque passage les lectures de sortie des pièces non vendues des variantes en attente : sans index sur
  ces lectures aujourd'hui (aucun portique réel avant L4). **À poser en L4** : un index partiel sur les `ZONE_SEEN` par heure,
  avec la mise à jour du test de migration de L2-a qui énumère les index de `loss_piece_event` ;
- chaque vente suivie sans pièce « couvre » pendant la fenêtre du rattrapage la sortie d'une pièce non vendue de sa variante, tous
  postes confondus : avec un étiquetage partiel, un vol d'un article courant peut être absorbé (voulu par le § 16.6, à garder en
  tête pour le constat « sortie sans vente » de L4) ;
- **la migration passe AVANT le code** : sans la table, chaque vente serait sautée pour toujours par l'analyse ;
- le lien entre un tapis et son poste est celui d'AUJOURD'HUI : une vente hors ligne synchronisée après que le tapis a changé de
  poste, ou a été retiré, n'est pas reliée par le tapis (elle reste rattrapable à la sortie) ;
- une vente lue avec retard (hors ligne) peut trouver sa pièce déjà reliée à une vente plus récente du même article ;
- une ligne de plus de 200 articles identiques n'en relie que 200 ;
- une entrée du journal qui fait échouer cette étape est sautée et comptée comme pour une règle (`ENTRIES_SKIPPED`, § 3) ; un
  échec du rattrapage est écrit au journal du serveur et n'arrête pas le passage.

**Tests** : `LossPreventionSaleLinkTest` (vente reliée par le tapis ; rien d'écrit dans la caisse ; rattrapage dans les deux sens,
la plus ancienne d'abord ; heure réelle ; fenêtres ; suivi à l'heure de la vente ; tapis d'un autre poste ; retour avec et sans
pièce relue, annulation, pièce revendue ; gardes de la base et rejeu de la migration ; réglages ; conservation ; avertissement du
tableau de santé ; analyse bloquée ; vente hors ligne avec décalage horaire et sans poste ; article dont toutes les pièces sont
vendues ; pièce rendue restée sur le tapis, puis reposée ; lecture de sortie d'avant le retour).

**Relecture indépendante** (16/11/2026) : rien n'est écrit dans la caisse, le rattrapage ne sort pas du site, une pièce n'est
vendue qu'une fois et un article n'a qu'une pièce (par la base et par le code) ; corrigés : l'heure d'une vente hors ligne prise
pour de l'UTC ; une lecture d'avant le retour d'une pièce réutilisée (la pièce rendue redevenait « vendue ») ; le lien par le
tapis tenté une seule fois ; « suivi » plus large qu'à la caisse ; une requête par article au rattrapage ; une erreur de type sur
une vente sans poste ; une pièce louée dite vendue pour toujours. **Seconde passe** : tout est corrigé, les trois points tiennent ;
deux corrections avaient ouvert un défaut chacune, corrigés à leur tour : une vente annulée puis ressaisie ne retrouvait plus sa
pièce (la règle « après le dernier retour » ne vaut plus que pour un retour d'articles), et la limite des lectures de sortie,
prise sur les plus anciennes, pouvait priver les ventes du jour de leur rattrapage.

### 16.19 Lot L3-b2b codé : la règle « articles suivis vendus sans lecture » (16/11/2026)

Contrat validé par le chef de projet (neuf points). **Aucune migration, aucune route nouvelle.** Code :
`Services/Rule/UnreadSoldRule`, une entrée de `LossRuleCatalog`, un type de preuve (`LossEvidence::SALE_UNITS`), un sujet de
constat (`cash_register`), un scénario d'exercice.

**La règle `UNREAD_SOLD`** (vague 3), **par poste de caisse et par journée du magasin, jamais par personne** :
- elle s'évalue à chaque **clôture d'un poste** (entrée `Z_REPORT_CREATED` du journal de caisse), **après le rattrapage** (tapis
  retenté, sortie : l'analyse le lance juste avant de présenter une clôture aux règles, seulement si cette règle est allumée et
  s'il y a du neuf depuis le dernier rattrapage du passage : début du passage, vente ou retour lu depuis ; le tapis n'est retenté
  qu'une fois par passage), pour le jour de la clôture ET pour **la
  dernière journée d'avant où ce poste a vendu des articles suivis** (la veille le plus souvent ; le samedi pour une boutique
  fermée le dimanche ; une semaine au plus) : celle-ci est rejugée une fois ses rattrapages terminés ;
- elle lit `loss_sale_unit` (§ 16.18) : les articles suivis vendus ce jour-là sur ce poste et pas revenus (`tracked`), et parmi
  eux ceux qui n'ont pas de pièce **alors que leur fenêtre de rattrapage est passée** (`unread`) ; un article encore rattrapable
  compte parmi les suivis, pas parmi les « sans lecture » ; un article revenu (retour, vente annulée) n'est plus compté ;
- constat si **les deux seuils** sont atteints : `unread >= minCount` (5 par défaut, de 1 à 500) ET `unread` fait au moins
  `minShare` % de `tracked` (20 par défaut, de 1 à 100). Réglages, interrupteur et aperçu sur 30 jours par les routes des règles
  (`GET /regles`, `PUT /regles/UNREAD_SOLD`, `POST /regles/apercu`), comme les autres ;
- **un constat par poste et par jour** (`UNREAD_SOLD:register:<poste>:day:<jour>`), sujet `{ type: cash_register, id, name,
  label: « Poste de caisse » }`, `onDuty: []`, `sessionId: null`, pas de montant en jeu ; résumé : « Articles suivis vendus sans
  lecture ce jour-là à ce poste : 3 sur 5 (60 %). » ;
- `facts { tracked, unread, counter, catchUp, returned, share, minCount, minShare, below }` ;
- **la vie du constat** : il s'ouvre quand les deux seuils sont atteints. Ensuite, une clôture qui trouve **d'autres nombres** y
  ajoute sa preuve (`kind: sale_units` : journée, articles suivis vendus, vendus sans lecture, lus sur le tapis, rattrapés à la
  sortie, revenus) et met les nombres à jour, **même s'ils sont passés sous les seuils** : le constat le dit alors (`facts.below`,
  résumé « …, sous les seuils depuis la dernière clôture. ») plutôt que d'afficher un chiffre que le rattrapage ou un retour a
  démenti ; le côté des seuils est alors jugé avec les seuils du moment. Une clôture qui trouve **les mêmes nombres n'ajoute
  rien** : ni preuve, ni « du nouveau depuis la décision » ; **un seuil changé ne touche donc pas un constat dont les ventes n'ont
  pas bougé** (§ 4). Une décision du gérant n'est jamais touchée : un constat déjà décidé dont les nombres changent garde son état
  et sa note, et porte « du nouveau depuis la décision » (`newEvidenceSinceDecision`), comme pour toute règle. (Précisions
  apportées par les relectures au point 5 du contrat validé.) ;
- **aucune personne** : ni compte, ni nom, ni vente dans le constat et dans ses preuves (consigne du chef de projet) ; le constat
  dit où et quand, pas pourquoi (tapis en panne, tapis non utilisé, puces retirées avant la caisse). Le renvoi de la preuve
  (`ref.type: closing`, `ref.id` : numéro du rapport de clôture, `sequence` et `hash` : son entrée au journal de caisse) dit quelle
  clôture a fait juger la journée : un repère de vérification, sans écran (`screen: null`), qui ne sert ni nom ni compte ;
  `onDutyNotice` est `null`
  pour un constat dont le sujet est un poste. Dans une boutique à un seul caissier par poste, « par poste et par jour » désigne de
  fait quelqu'un sans le nommer : c'est une lecture du gérant, pas une donnée du module.

**Mode exercice** : un scénario de plus, `unread_sold`, qui fabrique un constat fictif à partir des seuils du site (sujet « poste
de caisse », personne listé). **Pas de vente simulée** dans le boîtier simulé (décision du chef de projet : la table des articles
vendus ne porte que de vraies ventes de la caisse).

**Écran des pièces** : le filtre `status` accepte `sold` et `returned` en plus de `created` et `retired` ; la synthèse par article
et ses totaux gagnent `sold`, et « en service » (`active`, `pieces`) ne compte plus une pièce vendue (une pièce revenue y est de
nouveau).

**Pour le web et le mobile** : une règle de plus dans la liste des règles et dans le filtre des constats (`rule=UNREAD_SOLD`,
aussi dans les liens `pertes.constats` des courriels) ; un sujet de constat nouveau, `cash_register` ; un type de preuve nouveau,
`sale_units`, rendu comme les autres en lignes `{ label, value }`.

**Documentation** : page d'aide `docs/aide/pertes.md` (créée : le module pour l'administrateur) ; le geste « poser la pièce
rendue sur le tapis AVANT de valider le retour » dans `docs/caisse.md` et `docs/aide/caisse.md` ; la note pour le plan de L4 au
§ 16.6.

**À savoir** : la règle juge avec ce que l'analyse a relié au moment de la clôture, rattrapage compris ; elle ne regarde que le
jour de la clôture et la dernière journée d'activité d'avant (une vente hors ligne synchronisée plus tard, pour une journée plus
ancienne, n'est plus jugée) ; les articles vendus dans la dernière fenêtre de rattrapage avant une clôture ne sont jugés qu'à la
clôture suivante de ce poste (dans la semaine) ; un seuil changé ne vaut que pour les journées dont les nombres changent
ensuite et pour les constats à venir.

**Note pour le plan de L5 (les alertes), décision du chef de projet du 16/11/2026** : cette règle grossit dans la journée. Un
constat que le gérant a classé à midi (3 sur 6) garde sa décision si la clôture du soir trouve 15 sur 20 : il ne porte que « du
nouveau depuis la décision », comme pour toute règle, sans courriel ni vignette. C'est voulu pour ce lot (la décision reste au
gérant). À étudier en L5 : signaler de nouveau un constat classé dont les nombres ont doublé depuis la décision.

**Relecture indépendante** (16/11/2026) : aucune personne dans le constat ni ses preuves, règle bien par poste et par jour du
magasin, rien d'écrit hors du constat ; corrigés : la règle jugeait avant le rattrapage du même passage ; un constat n'était jamais
corrigé à la baisse ; la veille rejugée ajoutait une preuve identique à chaque clôture (« du nouveau depuis la décision » pour
rien) ; la fin d'une journée n'était jamais jugée si le poste ne fermait pas le lendemain ; le renvoi de la preuve désignait le
rapport de clôture. **Seconde passe** : aucun point bloquant ; corrigés : le rattrapage passait avant CHAQUE clôture d'un passage,
règle éteinte comprise (une reprise d'historique aurait refait des centaines de recherches par clôture) ; un seuil changé
modifiait à la clôture suivante un constat dont les ventes n'avaient pas bougé ; la règle lisait le constat existant par la
classe du puits (désormais `LossFindingSink::factsOf`) ; résumé rédigé sans accord en nombre (« 1 articles »).

**Tests** : `LossPreventionUnreadSoldTest` (catalogue et bornes ; les deux seuils ; article encore rattrapable ; rattrapage avant
la règle ; autre poste, article sans poste, journée trop ancienne non comptés ; dernière journée d'activité jugée ; mêmes nombres :
rien d'écrit ; article revenu et constat passé sous les seuils ; décision gardée ; seuil changé sans effet sur un constat aux
mêmes nombres ; règle éteinte ; aperçu sans écriture ; aucune personne dans le constat, sa preuve ni la base ; rattrapage avant
une clôture seulement s'il y a du neuf et si la règle est allumée ; écran des pièces), le scénario d'exercice dans `LossPreventionExerciseTest`.
Ajouté avec ce lot, pour le lot CONSTRUCTION (`docs/boutique-en-construction.md`) : sur un site neuf fermé en entier, le boîtier
s'inscrit, donne signe de vie et envoie ses faits, la caisse pose sa question et le gérant lit son matériel
(`LossPreventionCounterTest::testABoxAndATillWorkOnASiteStillUnderConstruction` ; un commerçant installe son matériel avant d'ouvrir).


### 16.20 Lot L3-c codé : l'imprimante à puce (16/11/2026)

Contrat validé par le chef de projet (dix points ; § 15 et § 16.7 font foi pour le fond). Une migration
(`Version20270118100000`, 23 instructions, script `scripts/migrate_all_v2_pertes_l3c.sh`), sept routes, deux faits nouveaux du boîtier. Code :
`Services/LossPrintService`, `LossTagEpc`, `LossTagVault`, `Repository/LossPrintRepository`, `Controller/LossPrintController`,
`UseCase/LossPrintUseCase` ; entités `LossPrintJob`, `LossPrintJobLine`, `LossTagSecret`. **Tout se joue sur le simulateur :
aucune imprimante réelle n'a été essayée** (campagne d'essai à l'achat du matériel).

**Le travail d'impression.** Le gérant choisit des pièces et une imprimante à puce branchée sur un boîtier ; la plateforme
enregistre un travail (`loss_print_job`) et une ligne par pièce (`loss_print_job_line`) avec l'identifiant à écrire. Le boîtier
vient chercher ses travaux (la plateforme ne joint jamais la boutique), imprime, écrit la puce, la **RELIT**, et renvoie un fait
par étiquette. États d'un travail : `waiting` (aucun résultat), `running` (au moins une ligne close), `done` (plus aucune ligne
en attente), `cancelled`. États d'une ligne : `waiting`, `written`, `failed` (avec `reason`), `cancelled` ; une ligne ne quitte
l'attente qu'une fois.

**Routes de l'administrateur** (`/api/gestion/pertes`, `ROLE_ADMIN`, gardes de la gestion et du module) :

| Route | Réponse |
|---|---|
| `GET /impressions` (`page`, `limit` ≤ 50) | `{ items [travail], total, page, limit, printers [{ deviceId, serial, model, status, statusLabel, boxId, boxName, boxEnrolled }], ready { ok, reason, message }, limits { lines: 200, openJobs: 20 }, exercise, screen: pertes.impressions }` |
| `POST /impressions` `{ deviceId, pieceIds, withPrice?, confirmTagged? }` | 201 le travail et ses lignes |
| `GET /impressions/{id}` | le travail, `lineItems` et `screen` |
| `POST /impressions/{id}/annuler` | le travail et `cancelledLines` ; rejouable ; ce qui est écrit le reste |
| `GET /exercices/impressions`, `GET /exercices/impressions/{id}` | les travaux de l'imprimante simulée, même forme ; ouvertes sans le droit du module |

Travail : `{ id, status, statusLabel, printer { deviceId, serial, model, boxId, boxName }, withPrice, lines, written, failed,
waiting, cancelled, createdAt, finishedAt, cancelledAt, exercise }`. Ligne (`lineItems`) : `{ id, position, piece { id, number,
name, variantLabel }, identifier, status, statusLabel, reason, reasonLabel, tagId, doneAt }`. `ready.reason` :
`PRINT_KEY_MISSING`, `PRINT_KEY_INVALID`, `PRINT_KEY_MISMATCH`, `NO_PRINTER`, ou `null`.

Refus de la création : 422 (`deviceId`, `pieceIds` : les règles des étiquettes de pièces, dont `PIECES_NOT_FOUND` et
`LABELS_LIMIT_EXCEEDED` au-delà de 200 lignes) ; 404
`PRINTER_NOT_FOUND` (inconnue, retirée, d'un boîtier révoqué, simulée, ou pas une imprimante) ; 409 `PRINT_KEY_MISSING` |
`PRINT_KEY_INVALID` | `PRINT_KEY_MISMATCH`, `PIECES_ALREADY_QUEUED` (`pieceIds` : elles attendent déjà dans un
travail), `PIECES_ALREADY_TAGGED` (`pieceIds` : elles portent déjà une puce ; `confirmTagged: true` pour leur en imprimer une de
plus), `PIECE_TAG_LIMIT`, `PIECE_RETIRED`, `BARCODE_MISSING`, `PRINT_JOBS_LIMIT` (20 travaux ouverts par site),
`PIECE_LABELS_EXHAUSTED` (256 étiquettes pour une même pièce), `PRINT_ON_REFERENCE_SITE` (le site de référence, que tout site
neuf copie, ne reçoit jamais de vrai travail : voir le mot de passe). Un refus n'écrit rien.

**Canal du boîtier.**
- `GET /api/pertes/boitier/travaux` (jeton `X-Loss-Box`) → `{ serverTime, jobs [{ id, printer (numéro de série), withPrice,
  access { rank, password }, lines [{ id, position, identifier, piece (numéro), title, subtitle, barcode, symbology, price,
  note }] }], more }`. Une lecture : rien n'est écrit. 50 lignes au plus par appel (`more` : il en reste). Seules les lignes en
  attente des travaux ouverts de CE boîtier, sur ses imprimantes en service. Les textes sont ceux des étiquettes de pièces, dans la
  langue du site ; le prix est celui du catalogue, hors promotion, si le travail le demande. `Cache-Control: no-store`. Une ligne
  qui ne peut pas être servie n'occupe pas la file et n'est pas annoncée par `more` : celle dont le mot de passe ne se relit pas
  (clé retirée ou autre clé : elle repart quand la bonne clé revient), celle d'un article qui n'a plus de code-barres, celle
  d'une imprimante retirée (ces deux-là attendent : annuler le travail). **Retirer une pièce clôt aussitôt ses lignes en
  attente** (`failed`, `piece_retired`) : rien ne sera imprimé pour elle, et son travail n'attend pas après elle.
- **Le jeton du boîtier suffit** pour lire ses travaux et le mot de passe (pas de preuve de la machine sur cet appel, comme pour
  les réglages) : un jeton copié, valable 24 heures, l'obtient, et révoquer le boîtier ne change pas un mot de passe déjà connu.
  C'est la protection légère du plan (§ 16.7) ; le rang prépare un changement de mot de passe.
- Deux faits de plus dans `POST /faits`, envoyés par un appareil de genre `printer` :
  `TAG_WRITTEN { key, type, at, device, data { line, identifier } }` (puce écrite et relue conforme) et
  `TAG_WRITE_FAILED { …, data { line, reason } }`, `reason` : `tag_unreadable`, `write_refused`, `verify_failed`,
  `printer_stopped`, `out_of_labels`. Réponse de chaque fait comme les autres : `accepted`, `duplicate` (la ligne est déjà close
  de la même façon, ou une écriture que la plateforme avait elle-même close en échec : rien de plus n'est posé), `refused` avec `PRINT_LINE_UNKNOWN` (ligne inconnue, ou d'un autre boîtier ou
  d'une autre imprimante), `PRINT_LINE_CLOSED` (résultat contraire après coup, ou ligne annulée), `IDENTIFIER_MISMATCH`
  (l'imprimante a relu autre chose que l'identifiant demandé : rien n'est associé, la ligne attend), `REASON_INVALID`,
  `TAG_INVALID`, `DEVICE_KIND_INVALID`, `DEVICE_UNKNOWN`, `FACT_INVALID`. Les réglages du boîtier (`facts.types`) annoncent les
  deux genres.
- **Une écriture réussie pose la puce sur la pièce**, comme le geste ordinaire : puce `uhf` associée, fait `TAG_ASSOCIATED`
  (source `box`, `data { technology, printLineId }`, clé d'idempotence propre à la ligne ; à l'heure du boîtier, ou à celle du
  serveur avec `clockDoubt` si son horloge est douteuse), `PAIR_SET` si la pièce portait déjà une puce. Si la pièce porte déjà
  quatre puces, ou si l'identifiant est déjà connu sur le site pour une autre puce, la ligne passe en échec (`tag_limit`,
  `identifier_taken`) et rien n'est associé ; si l'identifiant a été posé à la main sur SA pièce entre-temps, la ligne est écrite
  avec cette puce, sans doublon. Ordre des verrous, le même partout : les pièces (par identifiant croissant), les travaux, puis
  la ligne ; un lot de faits tient d'avance les pièces et les travaux de tous ses résultats d'impression, si bien qu'un lot, une
  annulation et un retrait de pièce ne s'interbloquent pas. Limite connue : l'analyse des ventes (§ 16.18) tient des pièces dans
  l'ordre des ventes ; si elle croise un lot de résultats sur les mêmes pièces, la base tue l'un des deux, qui se rejoue sans perte. Une étiquette écrite après
  l'annulation du travail n'est pas associée (`PRINT_LINE_CLOSED`) : sa puce se pose à la main, par le geste ordinaire.

**L'identifiant écrit dans la puce** (`LossTagEpc`, seule classe à connaître le découpage, à arrêter à l'essai du matériel) :
96 bits, 24 caractères hexadécimaux : marque de la plateforme (`F1A7`), site (4 octets : une empreinte de son code), numéro de la
pièce (4 octets), exemplaire (1 octet : 0 pour la première étiquette de la pièce, puis 1… : une pièce réétiquetée ne reçoit pas
deux fois le même identifiant), clé de contrôle (1 octet). Sans numérotation GS1. L'unicité sur le site est gardée par la base ;
entre deux sites, elle tient à l'empreinte du code du site (une puce d'un autre site est de toute façon inconnue ici).

**Le mot de passe d'accès des puces** (`LossTagVault`, table `loss_tag_secret`) :
- un par site, 32 bits tirés au hasard par la plateforme au premier travail, jamais nul ; rangé **chiffré** (chiffrement
  authentifié XChaCha20-Poly1305), avec son rang (`rank`, un seul aujourd'hui) et le rang de la clé qui l'a chiffré (`key_rank`) ;
- **le chiffre est lié au site et au rang** : copié dans la base d'un autre site, il ne se relit pas (`PRINT_KEY_MISMATCH`).
  Deux conséquences : (1) **le site de référence ne porte jamais de mot de passe** : le code y refuse tout vrai travail
  (409 `PRINT_ON_REFERENCE_SITE`, la base du site étant celle que les sites neufs copient) ; un site né d'une copie qui en
  porterait un ne pourrait pas imprimer sans intervention sur sa base ; (2) **changer le code d'un site rend son mot de passe
  illisible** (`PRINT_KEY_MISMATCH`) : à ne pas faire sur un site qui a écrit des puces sans prévoir le rechiffrement ;
- **il n'est remis qu'au boîtier**, dans `GET /travaux` (`access.password`, avec `access.rank`). Il n'apparaît dans aucune
  réponse à l'administrateur, aucun journal (ni du module, ni du serveur), aucun fait, aucun exemple JSON (masqué). Le journal du
  module note seulement qu'un mot de passe a été tiré : `TAG_SECRET_CREATED { rank, keyRank }`. Un essai le vérifie partout ;
- la base refuse de le supprimer et de changer son rang ; son chiffre et le rang de sa clé peuvent changer (changement de clé) ;
- protection légère, dite telle quelle : elle arrête la réécriture ordinaire, pas un attaquant équipé (§ 16.7).

**La clé de chiffrement : variable `LOSS_TAG_KEYS`, posée par le propriétaire** (décision du chef de projet : jamais dérivée du
secret du serveur, dont la rotation rendrait les puces déjà écrites impossibles à réécrire).
- Forme : `rang:clé`, la clé étant 32 octets au hasard en base64 ; plusieurs paires séparées par des virgules le jour où la clé
  change (`1:…,2:…`). Le plus grand rang chiffre ; un mot de passe rangé se déchiffre avec la clé de SON rang.
- **Geste du propriétaire, à faire une fois, à l'achat du matériel** : (1) tirer une clé : `openssl rand -base64 32` ; (2) ajouter
  au fichier d'environnement du serveur la ligne `LOSS_TAG_KEYS=1:<la clé tirée>` ; (3) recréer le conteneur de l'application
  (le fichier d'environnement n'est lu qu'à la création du conteneur), et celui des workers s'ils doivent un jour créer des
  travaux ; (4) garder une copie de la clé en lieu sûr, hors du serveur : **sans elle, les mots de passe rangés ne se relisent
  plus et les puces déjà écrites ne peuvent plus être réécrites** (elles restent lisibles). Ne jamais écrire la clé dans le
  dépôt, un message ou un journal.
- **Le marchand ne lit jamais le nom de la variable** (correctif du 16/11/2026, demande du web) : `ready.message`, le message du
  409 et le conseil du tableau de santé disent seulement que l'écriture des puces n'est pas activée ou pas disponible, et de
  contacter la plateforme. Le nom `LOSS_TAG_KEYS` reste pour l'exploitant : dans cette fiche, et dans le journal du serveur à
  chaque création refusée.
- **Tant que la variable manque** : aucun vrai travail ne se crée (409 `PRINT_KEY_MISSING`), l'écran le dit (`ready`), et la
  ligne de l'imprimante au tableau de santé reste « en attente » avec la même phrase. Illisible (pas 32 octets, pas de rang, rang
  répété) : `PRINT_KEY_INVALID`. Posée mais pas celle du mot de passe rangé : `PRINT_KEY_MISMATCH`, et les travaux en attente ne
  sont pas servis au boîtier (ils repartent quand la bonne clé revient).
- Changer de clé (plus tard, sans code de plus pour la lecture) : poser `1:<ancienne>,2:<nouvelle>` ; les mots de passe déjà
  rangés restent lus sous le rang 1. Le rechiffrement sous la nouvelle clé (une commande) est à écrire le jour venu ; la base le
  permet déjà.

**L'imprimante simulée d'un VRAI boîtier ne prend aucun vrai travail** (correctif du 16/11/2026, relevé par la relecture du
logiciel du boîtier). Un boîtier sans matériel, en démonstration, peut être relié à un vrai site et y annoncer des appareils
simulés (numéro de série qui commence par `SIM-`). Aucune puce n'existe derrière son imprimante : elle n'est pas comptée parmi
les imprimantes à puce d'un vrai travail (`LossPrintRepository::printers`), donc absente de `printers`, `ready.reason` vaut
`NO_PRINTER` si elle est seule, et `POST /impressions` sur elle répond 404 `PRINTER_NOT_FOUND`. En ceinture, `GET /travaux` ne
sert jamais de ligne pour une telle imprimante : le mot de passe des puces ne lui est jamais remis. Le logiciel du boîtier tient
la même règle de son côté (son imprimante de démonstration ne demande pas les travaux). Le mode exercice n'est pas touché.
- **Le préfixe `SIM-` est réservé** aux appareils simulés : c'est une convention tenue avec le logiciel du boîtier, pas une
  preuve. Une vraie imprimante dont le numéro de série commencerait par `SIM-` serait écartée de la même façon (aucun modèle
  visé n'en a) ; une imprimante simulée annoncée sous un autre nom ne le serait pas.
- **Le résultat aussi est refusé** : un `TAG_WRITTEN` ou un `TAG_WRITE_FAILED` pour une ligne d'une telle imprimante reçoit
  `PRINT_LINE_UNKNOWN` (`LossPrintRepository::lineRef`) ; aucune puce n'est posée sur une pièce par du matériel simulé.
- **Le tableau de santé et l'assistant d'installation** ne comptent pas non plus cette imprimante
  (`LossBoxRepository::livePrinters`) : pas de ligne « imprimante », pas de conseil d'imprimer, et l'étape `tag_printer` ne peut
  pas être « faite » par du matériel simulé (condition ferme du § 16.7). Le boîtier, lui, montre toujours ses appareils.
- **Avant ce correctif** (étape 87, 16/11/2026), rien n'empêchait de créer un vrai travail sur une telle imprimante. Aucun n'a pu
  l'être en production : la variable `LOSS_TAG_KEYS` n'y est pas posée, toute création répond `PRINT_KEY_MISSING`. Il n'y a donc
  rien à annuler à la bascule, et aucun mot de passe n'a été remis à un boîtier de démonstration.

**Le texte d'un motif d'échec dit quoi faire de l'étiquette** (chef de projet, 16/11/2026). Une étiquette dont le résultat est un
échec peut être sortie de l'imprimante, et sa puce peut même être bonne : le site ne la connaît pas. `reasonLabel` des motifs
`tag_unreadable`, `write_refused` et `verify_failed` se termine donc par « Ne posez pas cette étiquette : jetez-la, puis relancez
l'impression de cette pièce. » ; `printer_stopped` dit la même chose « si une étiquette est sortie pour cette pièce » ;
`out_of_labels` ne change pas (aucune étiquette n'est sortie). Le mode exercice montre les mêmes textes, pour que le gérant
lise à l'entraînement ce qu'il lira le jour venu, bien qu'aucune étiquette n'y existe. Le boîtier dit `verify_failed` aussi quand il ne sait plus ce
qu'une étiquette est devenue (service coupé pendant l'impression, résultat qui n'a pas pu être gardé) : il ne réimprime jamais
une étiquette au sort inconnu, deux puces porteraient le même identifiant.

**Mode exercice : l'imprimante simulée, SANS mot de passe.** Le boîtier simulé gagne un quatrième appareil
(`SIM-IMPRIMANTE`) et trois scénarios (`POST /exercices/boitiers/{id}/scenarios`) : `print_ok` (trois pièces d'exercice sans
puce reçoivent leur puce simulée), `print_refused` (la première étiquette échoue, `write_refused`), `print_no_labels` (la
première passe, puis `out_of_labels`). La réponse porte `printJobId`. 409 `EXERCISE_NO_UNTAGGED_PIECE` s'il n'y a aucune pièce
d'exercice sans puce, `EXERCISE_LIMIT_REACHED` au-delà de 60 puces simulées, `EXERCISE_NO_PRINTER` sur un boîtier simulé créé avant
ce lot (en créer un nouveau). Un travail d'exercice ne porte jamais de rang de mot de passe (la base le refuse) : l'exercice marche sans
la variable et ne touche jamais au mot de passe des vraies puces. (Écart signalé au chef de projet : il demandait une clé de
test réservée aux exercices ; ne rien chiffrer du tout est plus sûr.) Les travaux simulés s'effacent avec l'exercice.

**Tableau de santé et installation.** Une ligne par imprimante à puce (`code: printer-<id>`, `kind: printer`) : silencieuse si
elle ou son boîtier se tait ; en attente tant que la clé manque ou qu'elle n'a écrit aucune puce relue conforme ; en ordre
ensuite. Étape facultative `tag_printer` de l'assistant d'installation : faite quand une imprimante a écrit et relu une puce. Le
texte du niveau pro de la gamme (`GET /materiel`, besoin « imprimer l'étiquette ») renvoie aux impressions de puces.

**Ce que la base garde elle-même** (migration du lot) : un travail vise une imprimante de son boîtier, simulé pour un exercice,
vrai sinon ; un vrai travail dit le rang d'un mot de passe rangé, un travail d'exercice n'en porte jamais ; ce qui décrit un
travail ou une ligne ne change pas ; un travail fini ou annulé ne repart pas ; une ligne close ne change plus ; la puce d'une
ligne écrite est une puce en service, posée sur sa pièce, qui porte son identifiant ; une pièce n'attend que dans un travail à
la fois ; un identifiant n'est donné qu'à une ligne ; un mot de passe rangé ne se supprime pas. **Retour arrière de la
migration : il supprime les mots de passe rangés** ; sur une base où des puces ont été écrites, exporter `loss_tag_secret` avant.

**Journal du module** : `PRINT_JOB_CREATED { jobId, deviceId, lines, withPrice }`, `PRINT_JOB_CANCELLED { jobId, lines }`,
`TAG_SECRET_CREATED { rank, keyRank }`.

**Hors du lot** : le pilotage réel de l'imprimante (logiciel du boîtier, dépôt local, langage de l'imprimante), le changement de
mot de passe (le rang le prépare) et le rechiffrement sous une nouvelle clé, le contrôle des copies (écarté par le propriétaire),
la caméra (L3-d).

**Relecture indépendante** (16/11/2026) : aucune fuite du mot de passe ni de la clé, aucun point bloquant ; corrigés : deux clés
d'essai différentes sous le même rang dans la suite ; des lignes impossibles à servir occupaient la file et `more` l'annonçait à
tort ; une pièce retirée laissait sa ligne en attente pour toujours ; un résultat et une annulation pouvaient s'interbloquer
(ordre des verrous) ; la clé passait en argument nu d'un service construit à chaque requête (lecture au premier usage, paramètre
marqué sensible) ; le chiffre n'était lié ni au site ni au rang ; une écriture close en échec par la plateforme, rejouée,
répondait « refusé » ; la clé du fait d'association dépendait de celle choisie par le boîtier ; la garde ne vérifiait pas que la
puce d'une ligne écrite est en service ; index par pièce. **Seconde passe** : toujours aucune fuite, aucun point bloquant ;
corrigés : un lot de plusieurs résultats tenait un travail avant la pièce du résultat suivant (pièces et travaux tenus d'avance,
dans l'ordre) ; repli quand un article change entre deux lectures ; règle du site de référence gardée par le code ; le coffre
ne garde plus la variable après l'avoir lue.

**Tests** : `LossPreventionPrintTest` (travail servi à son boîtier et à lui seul, par 50 lignes ; résultats rejoués, refus,
annulation, réimpression à un autre exemplaire, confirmation d'une pièce déjà équipée, plafonds ; écritures qui ne peuvent plus
être associées, pièce retirée, article sans code-barres ; le mot de passe remis au boîtier seulement et écrit nulle part ; clé
absente, illisible, autre ; imprimante simulée ; imprimante simulée d'un vrai boîtier, jamais servie ; identifiant et coffre ;
gardes de la base et migration rejouée). La clé d'essai
est commune aux classes de la suite (`LossTagKeysTrait`).

## 17. Lot L4 : antennes de zone, portique, dépôt et comptage (plan validé par le propriétaire le 16/11/2026 ; rien n'est codé)

**Plan validé par le propriétaire le 16/11/2026, tel quel, avec les huit propositions du point 17.11** (relayé par le chef de
projet), plus un ajout sur les heures d'ouverture (17.4). Ordre : L4-a, L4-b, L4-c, L4-d, puis L4-e après l'avis de l'avocat. Le
contrat de chaque sous-lot va au chef de projet en dix lignes avant son code ; L4-b et L4-c passent par un relecteur neuf.

Il reprend le § 14 (points 1 et 3), le
§ 16.14 (une étape de l'assistant par antenne, une ligne de santé par appareil), la réserve du § 16.6 (rattrapage qui absorbe une
sortie) et les deux notes laissées pour la suite (§ 16.18, index des lectures de zone ; § 16.19, « du nouveau depuis la
décision »). Rien n'est codé. La caméra du dépôt (§ 14, point 2) reste avec L3-d, après l'avis de l'avocat : elle n'est pas dans
ce lot.

### 17.1 Ce que L4 apporte, et ce qu'il n'apporte pas

- **Apporte** : des **antennes de zone** branchées au boîtier (dépôt, porte du dépôt, rayon, sortie) et un **portique réel** à la
  sortie ; pour chaque pièce, **où elle a été vue en dernier** ; quatre constats nouveaux, tous **par lieu et par jour ou par
  pièce, jamais par personne** : « sortie sans vente », « sortie du dépôt sans mise en rayon », « porte arrière du dépôt » et
  « mouvement hors des heures d'ouverture » ; le constat « puce disparue après la zone sensible » (§ 14, point 1), éteint tant que
  l'avocat n'a pas rendu son avis ; le **comptage** au lecteur à main et le **bilan du soir** ; une étape de l'assistant et une
  ligne de santé par antenne ; les mêmes scènes en mode exercice et dans le simulateur du boîtier.
- **N'apporte pas** : aucune alerte sonore ou en caisse au moment de la sortie (L5) ; aucun avis de l'IA (L5) ; aucune caméra ;
  aucune écriture de vente, de stock ni de catalogue (§ 1) : le comptage du module ne corrige pas le stock, il le compare ;
  aucun constat qui nomme ou vise une personne ; aucune suite automatique, la décision reste humaine.

### 17.2 Les zones et les antennes

- **Zones** : celles du contrat (`stockroom`, `stockroom_door`, `floor`, `counter`, `exit`), plus deux nouvelles :
  `back_door` (la porte arrière du dépôt) et `restricted_entrance` (l'ENTRÉE de la zone sensible, jamais l'intérieur). Une
  antenne est un appareil `reader` avec une zone ; le lien se règle par la route qui existe déjà
  (`PUT /boitiers/{id}/appareils/{deviceId}`), dans l'assistant.
- **Ce que le boîtier envoie** : le fait `ZONE_SEEN` qui existe déjà, **à l'arrivée d'une pièce dans une zone et à son départ
  seulement** (jamais une ligne par lecture radio : une antenne lit la même puce des dizaines de fois par seconde). Le boîtier
  tient la présence en mémoire et n'envoie que les changements, comme le tapis. Côté plateforme, un plafond par pièce et par
  zone borne ce qu'un boîtier mal réglé peut écrire.
- **Où est la pièce** : deux colonnes sur la pièce, « dernière zone » et « vue à », écrites par les faits de zone. Les états
  réservés depuis L2 prennent vie par ces faits seulement : `in_stockroom`, `on_floor`, `at_counter`. `missing` vient du
  comptage (17.6). Une pièce vendue reste `sold` quoi qu'on lise ensuite.
- **Lectures parasites** : une antenne peut lire à travers une cloison. Parade côté boîtier : une pièce n'« arrive » dans une
  zone qu'après avoir été lue pendant une durée réglable (par défaut 3 secondes) et n'en « part » qu'après un silence réglable
  (par défaut 30 secondes). Ces deux durées sont des réglages servis au boîtier ; leurs bonnes valeurs se trouvent à l'essai du
  matériel (matériel jamais vu : § 13.7, risque 7).
- **Base** : un index partiel sur les `ZONE_SEEN` par heure (note du § 16.18), et la conservation des lectures de zone suit le
  réglage qui existe pour les présences du tapis.

### 17.3 Le portique et le constat « sortie sans vente »

- **Règle** : une vraie pièce jamais vendue (ni retirée) est lue à la sortie (`ZONE_SEEN`, zone `exit`), et aucune vente ne
  l'explique. Le constat est **par pièce** : il dit l'article, l'heure, l'antenne, et où la pièce avait été vue avant. Aucun nom.
- **Jamais avant que la caisse ait parlé** : l'analyse attend que son curseur ait dépassé l'heure de la lecture, plus la fenêtre
  du rattrapage, avant de juger (une vente hors ligne ou un boîtier en retard arrivent après). Une lecture à l'heure douteuse
  n'ouvre pas de constat.
- **Réserve du § 16.6, à trancher par le propriétaire.** Aujourd'hui, une vente suivie sans pièce « couvre » la sortie d'une
  pièce non vendue de la même variante : avec un étiquetage partiel, elle peut absorber la sortie d'une pièce volée.
  *Proposition : les deux parades ensemble.* (a) Le rattrapage silencieux ne vaut que pour une variante **entièrement
  étiquetée** (autant de pièces à puce non vendues que d'articles en stock, lu dans le catalogue sans rien y écrire) : la vente
  sans pièce ne peut alors être qu'une lecture manquée. (b) Sinon, le rattrapage a lieu quand même (le client honnête n'est pas
  inquiété), mais il ouvre un constat de **gravité faible**, « sortie couverte par une vente sans lecture », pour qu'une série
  se voie : trois dans la semaine sur le même article remontent en gravité.
- **Ce que le portique ne fait pas** : il ne sonne pas et ne retient personne (L5 décidera des alertes). Une pièce d'exercice
  ne déclenche jamais un vrai constat.

### 17.4 Le dépôt : trois constats

- **« Sortie du dépôt sans mise en rayon »** (§ 14, point 3) : une pièce passe la porte du dépôt vers la boutique
  (`stockroom_door`), puis n'est vue ni en rayon, ni au comptoir, ni vendue dans un délai réglable (par défaut 30 minutes).
  Le « transfert » n'est pas un geste à saisir : c'est la lecture en rayon qui le prouve.
- **« Porte arrière du dépôt »** : une pièce lue à `back_door` et qu'aucun retour au fournisseur ni retrait de pièce n'explique
  dans la journée. Sans antenne à cette porte, la règle est éteinte et l'écran le dit.
- **« Mouvement hors des heures d'ouverture »** : une pièce qui change de zone, ou passe une porte, alors que le magasin est
  fermé. Les heures sont un réglage du module (une plage par jour de la semaine, à l'heure du magasin) ; sans heures réglées,
  la règle est éteinte. **Ajout du chef de projet (16/11/2026)** : si la boutique a déjà saisi ses heures ailleurs, le module
  les reprend par défaut et son réglage ne sert qu'à les remplacer. **Relevé du 16/11/2026, en lecture** : aucune heure
  d'ouverture du magasin n'existe aujourd'hui dans la plateforme. La fiche de l'entreprise n'en a pas ; les réservations ont une
  seule plage pour tous les jours (`booking_configuration.opening_start / opening_end` : les heures où une réservation peut
  commencer, pas celles du magasin) ; l'assistance a des jours ouvrés (`support_setting.business_days`), sans heures. À trancher
  au contrat de L4-c : proposer ces valeurs comme point de départ à confirmer par le gérant, plutôt que les appliquer en
  silence (des heures fausses feraient de faux constats). Le comptage et la mise en rayon du matin ne doivent pas déclencher : une marge réglable avant
  l'ouverture et après la fermeture (par défaut 1 heure).
- Les trois sont **par pièce et par lieu**, avec un regroupement par jour et par zone quand plusieurs pièces bougent ensemble
  (un carton entier ne fait pas vingt constats).

### 17.5 La zone sensible (§ 14, point 1)

- Antenne à l'ENTRÉE de la zone, jamais dedans, sans caméra ni son, avec une affichette (texte à faire valider par l'avocat).
- **Constat « puce disparue après la zone sensible »** : une pièce non vendue lue à `restricted_entrance`, puis plus jamais lue
  ailleurs ni vendue dans un délai réglable (par défaut jusqu'à la fermeture du jour). Par pièce, sans personne.
- **Éteint d'office partout** tant que l'avocat n'a pas rendu son avis (§ 14, point 6) : un interrupteur par pays et région,
  comme les règles par personne ; la règle et l'étape de l'assistant existent, le super administrateur ne peut pas l'allumer
  avant une décision écrite du propriétaire. Codé en dernier.

### 17.6 Le comptage et le bilan du soir

- **Comptage** : le gérant ouvre un comptage (tout le magasin, ou une zone), passe le lecteur à main, ferme le comptage. Le
  boîtier marque ses lectures du numéro du comptage. Résultat : les pièces attendues (à puce, ni vendues ni retirées) et **non
  lues**. Une pièce absente à **deux comptages de suite** passe à `missing` et ouvre un constat « pièce absente au comptage »,
  groupé par comptage. Une pièce `missing` relue redevient ce qu'elle était, et le constat le dit.
- **Le comptage ne touche pas au stock** : il affiche l'écart entre le stock du catalogue et les pièces à puce comptées, par
  article ; la correction du stock reste un geste de la gestion.
- **Bilan du soir** (une lecture, calculée à la demande, puis jointe au résumé du jour) : articles suivis vendus avec et sans
  lecture, sorties sans vente, pièces sorties du dépôt, pièces non vues depuis N jours, antennes en panne dans la journée.
  Aucun nom, aucun classement de personnes ou de postes.

### 17.7 Assistant, santé, exercice, boîtier

- **Assistant** (§ 16.14) : une étape par antenne, « l'antenne lit une puce d'essai dans la bonne zone » (le fait
  `TEST_TAG_READ` existe déjà), et une étape « heures d'ouverture réglées ». Quand la vérification échoue, l'étape dit quoi
  essayer.
- **Santé** : une ligne par antenne (en ordre, silencieuse depuis N minutes, jamais vue), dans le tableau qui existe.
- **Exercice** : le boîtier simulé gagne des antennes simulées et une scène par constat (sortie sans vente, sortie couverte,
  sortie du dépôt sans mise en rayon, porte arrière, hors des heures, zone sensible, comptage avec une pièce absente).
- **Logiciel du boîtier** (dépôt local) : un lot B5, antennes simulées et présence par zone avec ses deux durées ; les pilotes
  des vraies antennes viennent à l'essai du matériel, comme ceux du tapis et de l'imprimante.

### 17.8 Deux corrections à embarquer

1. **« Du nouveau depuis la décision » à la seconde** (§ 16.19) : aujourd'hui comparé par les dates, à la seconde ; une preuve
   arrivée dans la seconde de la décision n'est pas vue. À comparer par le **rang** de la dernière preuve connue au moment de
   la décision (une colonne additive sur le constat). À faire en tête de L4-b : les constats par pièce reçoivent des preuves en
   rafale.
2. **Constat classé dont les nombres doublent** (note du § 16.19 pour L5) : hors de L4, rappelé ici pour qu'il ne se perde pas.

### 17.9 Découpage proposé

| Sous-lot | Contenu | Risque |
|---|---|---|
| **L4-a** | zones nouvelles, arrivée et départ par zone, « où est la pièce », index, santé et assistant des antennes, antennes simulées (exercice et boîtier B5) | ordinaire : je me vérifie |
| **L4-b** | correction « du nouveau depuis la décision » ; constat « sortie sans vente » ; la réserve du rattrapage tranchée | **à risque** : relecteur neuf et mutations |
| **L4-c** | heures d'ouverture ; les trois constats du dépôt | **à risque** (faux constats) : relecteur neuf |
| **L4-d** | comptage, `missing`, bilan du soir, résumé du jour | ordinaire |
| **L4-e** | zone sensible : règle, interrupteur, affichette ; éteinte d'office | **à risque** (vie privée) : relecteur neuf, après l'avocat |

Une migration additive par sous-lot, annoncée au chaperon avec son empreinte. Tout se joue sans matériel (exercice et
simulateur) ; la campagne d'essai sur de vraies antennes reste avant tout client. Le contrat de chaque sous-lot va au chef en
dix lignes avant son code, et les routes nouvelles au web avant la bascule.

### 17.10 Risques

1. **Faux constats** (lecture à travers une cloison, pièce posée près de la sortie, employé qui range) : arrivée et départ
   avec durées, attente de la caisse, marges autour des heures, gravité faible par défaut pour tout ce qui n'est pas une sortie
   sans vente, regroupement par jour et par zone.
2. **Sortie volée absorbée par le rattrapage** : 17.3.
3. **Volume** : arrivées et départs seulement, plafond par pièce et par zone, conservation bornée.
4. **Vie privée** : aucun fait ne désigne une personne ; la zone sensible est éteinte d'office ; les heures d'ouverture ne
   servent qu'aux pièces.
5. **Matériel jamais vu** : portée réelle des antennes, lecture d'une puce contre le corps ou dans un sac, portique étroit ou
   large : rien de cela ne se règle avant l'essai. Le plan ne promet pas qu'une sortie est toujours lue.
6. **Stock du catalogue inexact** : il sert à dire « entièrement étiquetée » (17.3) ; un stock faux fait pencher vers le
   constat faible, jamais vers le silence.

### 17.11 Questions au propriétaire, avec la proposition

1. **Rattrapage** : *les deux parades ensemble (17.3) : silencieux pour une variante entièrement étiquetée, constat faible
   sinon.*
2. **Seuils par défaut** : *sortie sans vente : un constat par pièce, gravité haute ; sortie du dépôt sans mise en rayon :
   30 minutes ; marge autour des heures : 1 heure ; absente au comptage : deux comptages de suite.*
3. **Heures d'ouverture** : *un réglage du module, une plage par jour ; sans réglage, la règle « hors des heures » est
   éteinte.*
4. **Porte arrière** : antenne (elle dit quelle pièce sort) ou simple contact de porte (il dit seulement que la porte
   s'ouvre) ? *Proposition : antenne ; le contact de porte ne prouve rien sur une pièce.*
5. **Zone sensible** : *codée en dernier, éteinte d'office partout, allumée seulement après l'avis écrit de l'avocat.*
6. **Le comptage change-t-il le stock ?** *Non : il montre l'écart, la gestion corrige.*
7. **Bilan du soir par courriel** : *joint au résumé du jour qui existe, sans courriel de plus.*
8. **Ordre** : *L4-a, L4-b, L4-c, L4-d, puis L4-e après l'avocat.*

### 17.12 Lot L4-a codé : zones et antennes (16/11/2026)

Contrat validé par le chef de projet le 16/11/2026, avec deux écarts au plan : **l'état d'une pièce ne change pas** (la zone
s'affiche à côté de l'état ; les états `in_stockroom`, `on_floor`, `at_counter` restent réservés), et **aucune zone nouvelle**
dans ce lot (`back_door` vient avec L4-c, `restricted_entrance` avec L4-e). **Codé sous Opus, à faire vérifier par Fable**
(demande du propriétaire, 16/11/2026). Migration `Version20270120100000` (script `scripts/migrate_all_v2_pertes_l4a.sh`, 13
instructions) : table `loss_zone_presence`, sa garde, deux colonnes de `loss_setting`. Aucune fonction ni table d'un lot précédent
n'est remplacée ni indexée.

- **Antenne de zone** : un lecteur (`reader`) qui a une zone, réglée par la route qui existe
  (`PUT /boitiers/{id}/appareils/{deviceId}` `{ zone }`). Un lecteur sans zone reste un lecteur à main.
- **Faits du boîtier** : `ZONE_SEEN` (l'arrivée, qui existait) et **`ZONE_LEFT`** (nouveau : le départ), tous deux avec leur
  `zone`, refusés `ZONE_INVALID` sans zone connue, `DEVICE_KIND_INVALID` s'ils viennent d'un tapis. Un boîtier qui ne dit que
  les arrivées marche toujours.
- **Présence par zone** (`loss_zone_presence`, `Entity/LossZonePresence`) : la projection de ces faits, tenue par le serveur
  comme celle du tapis. Une arrivée ouvre une présence (une puce n'est vue que par une antenne à la fois : ce qu'une autre
  croyait voir se ferme) ; une arrivée plus ancienne que ce qu'on connaît, ou à l'heure douteuse, ne pose rien ; un départ ferme,
  à l'heure du serveur s'il est douteux ; un départ plus ancien que l'arrivée connue ne ferme rien ; un fait rejoué ne touche
  rien deux fois. La base garantit : un lecteur et lui seul, une puce du même genre et sa pièce, une zone, une fin après le début,
  rien de l'identité ne change. Conservation : la règle des présences du tapis (`presenceRetentionDays`, 90 jours), **sauf la
  dernière présence de chaque pièce**, toujours gardée. Effacée avec l'exercice.
- **Réglages** (`GET/PUT /reglages`, bloc `pieces`) : `zoneArriveSeconds` (3 par défaut, 1 à 60) et `zoneLeaveSeconds` (30 par
  défaut, 5 à 600), avec leurs bornes et leurs valeurs par défaut. **Servis au boîtier** dans ses réglages :
  `zonePresence { arriveSeconds, leaveSeconds }` ; `facts.types` gagne `ZONE_LEFT`. Le texte de la règle de conservation
  (`pieces.note`) dit maintenant aussi les zones.
- **« Où est la pièce »** : la fiche et la liste des pièces gagnent `zoneLabel`, `zoneSeenAt`, `zoneLeftAt` (null tant
  qu'aucun départ n'est connu), `zoneDevice` (le numéro de série de l'antenne) ; `zone` (qui existait et valait toujours null)
  dit la dernière zone. C'est une dernière lecture, pas une certitude. La synthèse (`GET /pieces/synthese`) gagne `zones` :
  pour chaque zone, les vraies pièces en service vues en dernier là sans départ connu, avec les filtres de la synthèse.
- **Tableau de santé** : une ligne par antenne (`kind: zone_reader`, code `reader-{id}`, libellé « Antenne : Réserve »), en
  ordre, en attente (aucune puce vue) ou en panne ; son conseil nomme la zone.
- **Assistant** : étape facultative `zone_reader`, faite quand **chaque** antenne a vu une puce dans sa zone ; sinon elle
  reprend la ligne de l'antenne qui demande un geste (en panne d'abord).
- **Exercice** : le boîtier simulé gagne deux antennes (`SIM-ANTENNE-RESERVE` à la réserve, `SIM-ANTENNE-RAYON` en rayon ; son
  lecteur de sortie, qui a une zone, devient lui aussi une ligne d'antenne) et la scène `zone_move` « De la réserve au rayon »
  (trois faits : arrivée, départ, arrivée). La scène `zone_exit` pose aussi la présence à la sortie. 409
  `EXERCISE_NO_ZONE_READER` pour un boîtier simulé d'avant ce lot.
- **Logiciel du boîtier** (dépôt local, lot B5) : la classe `Antenne` lit sans cesse ce qui passe et ne met en file que les
  arrivées (après `arriveSeconds` de lecture, datée de la première lecture) et les départs (après `leaveSeconds` de silence,
  datés de la dernière lecture) ; une lecture isolée à travers une cloison ne produit rien ; mémoire bornée.
- **Ce que le lot ne fait pas** : aucun constat, aucune alerte, rien dans la caisse, rien dans le stock, aucune route nouvelle.

**Tests** : `LossPreventionZoneTest` (arrivée, départ, ordre des faits, heure douteuse, rejeu, une antenne à la fois, fiche,
liste, synthèse et ses filtres, réglages et leurs bornes, réglages servis, santé et étape, antenne en panne, pièce retirée,
conservation ; antennes simulées, scène, exercice étanche au réel, gardes de la base, migration rejouée et son script).
