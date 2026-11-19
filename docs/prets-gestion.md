# Prêts de la gestion (lot FIS-1)

Codé le 19/11/2026 sur le contrat validé par le chef de projet (plan `docs/plan-impots.md` § 3.1 et lot FIS-1 du § 4, branche
`docs-plan-impots`). Code : `src/Gestion/Loan/` (calcul et service), `src/Gestion/Controller/GestionLoanController.php`,
`src/Gestion/UseCase/GestionLoanUseCase.php`. Table `gestion_loan` (base du site), migration `Version20270201600000`, script
`scripts/migrate_all_v2_gestion_loans.sh`.

## 1. Le principe

- La **fiche** du prêt est stockée ; l'**échéancier** est **calculé** à chaque lecture (`LoanScheduleCalculator`, calcul pur), jamais
  stocké. Changer la fiche ou ajouter un remboursement anticipé recalcule tout.
- Le prêt alimente trois calculs existants, sans rien écrire ailleurs :
  - la **trésorerie** : le déblocage entre, chaque échéance entière sort (capital compris), les frais et les remboursements anticipés sortent ;
  - le **résultat** (période, année, statistiques) : seulement les **charges financières** (intérêts, assurance, frais), au prorata ;
  - le **rapprochement bancaire** : les échéances sont des attendus, comme les mouvements prévus.
- **Le capital remboursé n'est jamais une charge** : il ne touche que la trésorerie. Les écrans le montrent à part du coût du crédit
  (décision du chef, 19/11/2026).

## 2. La fiche

| Champ | Forme | Refus (422 `VALIDATION_FAILED`, `fields`) |
|---|---|---|
| `label` | texte, 120 caractères au plus | `LOAN_LABEL_INVALID` |
| `lender` | texte libre facultatif (120) ; jamais transmis à l'IA | — |
| `amount` | cents, de 1 à 1 milliard d'unités | `LOAN_AMOUNT_INVALID` |
| `currency` | code ISO ; par défaut la devise du site | `LOAN_CURRENCY_INVALID` |
| `ratePpm` | taux annuel en millionièmes (`45000` = 4,5 %), de 0 à 30 % | `LOAN_RATE_INVALID` |
| `durationMonths` | durée en mois, différé compris ; multiple du rythme ; 600 au plus | `LOAN_DURATION_INVALID` |
| `frequencyMonths` | `1` (chaque mois, par défaut) ou `3` (chaque trimestre) | `LOAN_FREQUENCY_INVALID` |
| `disbursedOn`, `firstDueOn` | `AAAA-MM-JJ` ; la première échéance tombe APRÈS le déblocage, au plus deux rythmes plus tard (ses intérêts valent un rythme ; un écart plus grand se saisit en différé) | `LOAN_DATE_INVALID` |
| `type` | `annuity` (échéances constantes, par défaut) ou `constant_capital` | `LOAN_TYPE_INVALID` |
| `deferralMonths`, `deferralKind` | différé en mois (multiple du rythme, moins que la durée) ; `partial` (intérêts seuls) ou `total` (rien n'est payé, les intérêts s'ajoutent au capital) | `LOAN_DEFERRAL_INVALID` |
| `insuranceMode`, `insuranceAmount`, `insuranceRatePpm` | `null` ; `fixed` (montant par échéance) ; `initial` ou `remaining` (taux annuel sur le capital initial ou restant dû) | `LOAN_INSURANCE_INVALID` |
| `fees` | frais de dossier, cents (21 474 836,47 au plus, comme l'assurance par échéance et les frais d'un remboursement anticipé) | `LOAN_FEES_INVALID` |
| `prepayments` | liste de `{ date, amount, fees }` (24 au plus ; après le déblocage, avant la dernière échéance ; pas plus que le capital restant dû à sa date : il doit s'appliquer en entier) | `LOAN_PREPAYMENT_INVALID` |
| `notes` | texte libre facultatif (1000) ; jamais transmis à l'IA | — |

## 3. Les règles de calcul (décisions du chef)

- Intérêts d'une échéance : capital restant × taux annuel ÷ 12 (mensuel) ou ÷ 4 (trimestriel), comme les banques pour un prêt amortissable.
- Arrondi au cent à chaque échéance (demi vers le haut) ; la **dernière échéance porte l'écart** : la somme des capitaux remboursés égale
  exactement le montant emprunté (plus les intérêts capitalisés d'un différé total, `capitalized`).
- **Différé total** : rien n'est payé (l'échéance ne porte que l'assurance), mais les intérêts **courent** : ils sont une charge de la
  période (`interest` de la ligne) et une part du coût du crédit, et s'ajoutent au capital restant dû, remboursé ensuite avec lui.
  `totals.capital` reste le montant emprunté ; `totals.paid` = `capital` + `creditCost` = tout ce qui sort pour le prêt.
- **Frais de dossier** : une charge **le jour du déblocage** (dans le résultat de la période qui contient ce jour, et dans la trésorerie ce jour-là).
- **Remboursement anticipé** : il réduit le capital juste après la dernière échéance qui le précède ; les échéances suivantes sont
  recalculées sur la durée restante : **la date de fin ne change pas, ce sont les échéances qui baissent**. Ses frais sont une charge à sa date.
- **Charges dans le résultat** : intérêts et assurance de chaque échéance répartis au prorata des jours entre l'échéance précédente (ou le
  déblocage, inclus) et elle (exclue) ; une période en reçoit la part de ses jours. Le calcul arrondit le **cumul** couru (charges d'une
  plage = cumul à sa fin − cumul à son début) : des périodes consécutives s'additionnent exactement au total de l'échéancier, sans cent
  perdu ni gagné.
- **Simplifications**, dites telles quelles aux applications :
  - un prêt dans une **autre devise que celle du site** reste dans la liste et sa fiche, mais n'entre ni dans la trésorerie, ni dans le
    résultat, ni dans les statistiques (aucune conversion) ;
  - pas de taux variable, pas de jours exacts (intérêts « ÷ 12 »), pas de modulation d'échéance : ces cas se saisissent en mouvement prévu ;
  - un remboursement anticipé réduit le capital dès l'échéance qui le précède : les intérêts courus entre elle et sa date ne sont pas
    comptés (au plus un rythme d'intérêts sur le montant remboursé) ;
  - un très long prêt à taux élevé laisse un écart d'arrondi visible sur la dernière échéance (de l'ordre de quelques dollars sur un prêt
    courant).
- **Le jour** de « capital restant dû » et de « prochaine échéance » est le jour du magasin (fuseau du site), comme la trésorerie et la console.

## 4. Routes (rôle de la gestion, comme la trésorerie)

| Route | Rend |
|---|---|
| `GET /api/gestion/prets` | `{ loans: [...] }` : les prêts, du plus récent au plus ancien, chacun avec son résumé du jour |
| `POST /api/gestion/prets/apercu` | l'échéancier d'une fiche **sans rien écrire** : `{ loan, schedule, totals }` |
| `GET /api/gestion/prets/exercice?from=&to=` | par prêt, pour un exercice : `capitalStart`, `capitalEnd`, `capitalRepaid`, `interest`, `insurance`, `fees` (refus `INVALID_DATE`, `INVALID_RANGE`) |
| `POST /api/gestion/prets` (201) | la fiche créée, avec son résumé et son échéancier |
| `GET /api/gestion/prets/{id}` | la fiche, son résumé et `schedule` |
| `PATCH /api/gestion/prets/{id}` | la fiche modifiée (champs absents gardés) |
| `DELETE /api/gestion/prets/{id}` (204) | — |
| `POST /api/gestion/prets/{id}/remboursements` (201) | ajoute `{ date, amount, fees? }` ; rend la fiche recalculée |
| `DELETE /api/gestion/prets/{id}/remboursements/{index}` | retire le remboursement de ce rang (ordre des dates) ; `LOAN_PREPAYMENT_NOT_FOUND` |

Un prêt inconnu : 404 `LOAN_NOT_FOUND`. Création, modification et suppression sont écrites au journal des modifications (ressource
`gestion-prets`).

**Résumé d'un prêt** (liste et fiche) : les champs de la fiche, plus `remainingToday` (capital restant dû à la fin du jour),
`nextInstalment` (la prochaine échéance non nulle, ou `null`), `lastDueOn`, `capitalized`, et `totals` :
`{ capital, interest, insurance, fees, prepaymentFees, creditCost, paid }`. **`creditCost` = intérêts + assurance + frais** : c'est le
**coût total du crédit**, à montrer en clair ; `capital` est le capital remboursé, à montrer à part.

**Une échéance** (`schedule[]`) : `{ n, date, periodStart, capital, interest, insurance, total, remaining, deferral, prepayment }`.

## 5. Ce qui change dans les autres écrans

- **Trésorerie** (`GET /api/gestion/tresorerie`, `docs/tresorerie-gestion.md`) : les flux des prêts sont dans la projection, rangés dans la
  catégorie `loan`. Si un mouvement prévu de catégorie `loan` saisi à la main existe à côté d'un prêt, la remarque
  `LOAN_MAY_OVERLAP_MOVEMENTS` le dit : le gérant choisit lequel garder, le serveur ne supprime rien.
- **Rapprochement bancaire** : les échéances, le déblocage, les frais et les remboursements anticipés entrent dans l'attendu.
- **Résultat d'une période** (`GET /api/periodes/{id}/resultat`) : bloc `charges: { financial: { interest, insurance, fees, total, loans },
  total }` et `result.netAfterCharges` = `netAfterFixed` − charges, avec `netAfterChargesRate`. `net` et `netAfterFixed` ne changent pas.
  **Une période figée avant le lot** garde son instantané : `charges: null`, `chargesNote: "CLOSED_BEFORE_LOANS"`, et
  `result.netAfterCharges` / `netAfterChargesRate` à `null`. `charges.caveats` porte `LOAN_MAY_OVERLAP_MOVEMENTS` quand un mouvement
  prévu « prêt » saisi à la main (éventuellement compté en frais fixe) existe à côté d'un prêt : le gérant choisit lequel garder.
- **Année** (`GET /api/gestion/statistiques/annee`) : par période, `financialCharges` et `netAfterCharges` (`null` pour une période
  figée avant le lot). Les mois, les totaux, la rentabilité et le point mort de l'année n'intègrent pas encore les charges (lot FIS-6).
- **« Toutes les boutiques »** (directeur d'un groupe) : le résultat du groupe porte `netAfterCharges` ; les prêts entrent dans
  l'empreinte du cache (journée, année, résultat). Le directeur peut créer, modifier ou supprimer un prêt ; l'ajout et le retrait d'un
  remboursement anticipé lui sont fermés (règle « remboursements » de sa garde, prudente).
- **Statistiques** (`GET /api/gestion/statistiques?sections=charges`) : `charges: { financial, previous, lastYear, capitalRepaid }` ;
  `capitalRepaid` (de la plage) est de la trésorerie, jamais additionné aux charges. Les charges y sont recalculées en direct, même sur
  une plage qui contient une période figée.

## 6. Outils de l'IA

Mêmes accès que l'écran (rôle de la gestion), aucune donnée personnelle : ni le prêteur ni les notes ne sortent vers le modèle.

- **Lecture (console IA, `docs/console-gestion.md`)** : analyse nommée `loans.overview`, écran `prets` (thème « argent »). Chiffres :
  `loansCount`, `remainingCapital`, `nextInstalmentAmount`, `nextInstalmentDate`, `creditCost`, `chargesToDate`, `chargesLeft` (prêts
  dans la devise du site) ; tableau par prêt : `label`, `currency`, `remaining`, `rate` (%), `nextDate`, `nextAmount`, `creditCost`, `lastDate`
  (un prêt dans une autre devise garde sa ligne sans montant : la console écrit les montants dans la devise du site).
- **Actions proposées puis confirmées (fil de la gestion, `docs/conversation-gestion.md` § 14)** : `loan.create` et `loan.prepayment`.
  L'aperçu est calculé par le serveur ; rien n'est écrit avant « Confirmer » ; annulation sous 24 h si la fiche n'a pas changé.
- **Lexique** : notions `capital-restant-du`, `cout-du-credit`, `charges-financieres` ; guide de l'écran `prets`.
- Un texte saisi dans le libellé est une donnée, jamais une consigne : la console ne lit que les chiffres et les libellés que le serveur
  lui donne (essai d'injection dans les essais du lot).
