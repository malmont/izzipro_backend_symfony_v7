# Frais fixes mensuels — application de gestion (lot FF1, 11/11/2026)

Principe décidé le 09/11/2026 (propositions de pilotage), contrat validé par l'application (« new easymakemoney ») le 11/11/2026 : les frais
fixes (salaires, loyer, assurances, abonnements, énergie et télécoms…) se saisissent **une fois**, comme mouvements prévus de la trésorerie
(`docs/tresorerie-gestion.md` § 2), sans deuxième table ; ils sont répartis sur les périodes au prorata de leur durée. `ROLE_ADMIN`, montants en
cents dans la devise du site.

Code : `Services/GestionService/GestionFixedCostService` (règle, équivalent mensuel, liste, prorata), `UseCase/GestionUseCase/GestionFixedCostUseCase`,
`Controller/GestionController/GestionFixedCostController` ; saisie par `GestionCashService` (mouvements) ; résultat par `PeriodResultCalculator`.
Test : `tests/Functional/Gestion/FixedCostApiTest.php`.

## 1. Qu'est-ce qu'un frais fixe

- Un mouvement prévu **sortant et récurrent** (`weekly`, `monthly`, `quarterly`, `yearly`). Un mouvement ponctuel (`none`) ou une entrée n'en est
  jamais un, même coché : une dépense ponctuelle va en note de frais.
- Choix du gérant : `fixedCost` (booléen) sur `POST` / `PATCH /api/gestion/tresorerie/mouvements` ; `null` (ou absent à la création) = règle
  par défaut ; 422 `MUST_BE_BOOLEAN`. Un `PATCH` sans `fixedCost` garde le choix.
- Règle par défaut (`GestionCashMovement::FIXED_COST_CATEGORIES`) : `rent`, `salaries`, `insurance`, `subscription`, `utilities` → oui ;
  `loan` (le capital remboursé n'est pas une charge), `tax`, `other` → non, cochables.
- Catégorie `utilities` (« Énergie et télécoms » : électricité, eau, internet), ajoutée par le lot.
- Chaque mouvement rendu gagne `fixedCost` (valeur effective), `fixedCostDefault` (vrai quand la règle s'applique) et `monthlyEquivalent`.
- **Règle d'usage** : un frais fixe ne se saisit pas aussi en note de frais, sinon il compte deux fois dans le résultat après frais fixes
  (remarque `FIXED_COSTS_MAY_OVERLAP_NOTES`, § 3).

## 2. Équivalent mensuel et liste

- `weekly` × 52 ÷ 12, `monthly` × 1, `quarterly` ÷ 3, `yearly` ÷ 12, arrondi au cent.
- `GET /api/gestion/frais-fixes?all=&locale=` → `{ currency, monthlyTotal, byCategory [{ category, label, monthly }], items [{ id, label,
  category, categoryLabel, amount, recurrence, monthlyEquivalent, startDate, endDate, active, fixedCostDefault }] }`. Par défaut, les frais
  fixes actifs aujourd'hui (début ≤ aujourd'hui, fin absente ou ≥ aujourd'hui) ; `all=1` ajoute ceux à venir et terminés (`active` faux),
  `monthlyTotal` et `byCategory` restant ceux des actifs.

## 3. Résultat de période (P2, `docs/periodes-gestion.md`)

- En ajout, le résultat actuel ne change pas : `fixedCosts { total, byCategory [{ category, label, amount }], days, caveats }` et
  `result.netAfterFixed` = `net` − `fixedCosts.total`, `result.netAfterFixedRate` = netAfterFixed ÷ ventes au coût connu × 100 (null si nul).
- **Prorata** : équivalent mensuel × 12 ÷ 365 par jour, pour chaque jour de la période situé entre le début et la fin du mouvement, quelle que
  soit la date de paiement réelle (`FIXED_COSTS_PRORATED`). La trésorerie, elle, suit les échéances.
- `FIXED_COSTS_MAY_OVERLAP_NOTES` : une note de frais de la période dont le nom ou la description évoque un frais fixe (loyer, salaire, paie,
  assurance, abonnement, électricité, hydro, internet, téléphone…) ; `notes` donne leurs noms (5 au plus). Rien n'est déduit automatiquement.
- Période fermée : le résultat figé à la fermeture contient `fixedCosts`. Une période fermée **avant** le lot garde son instantané, sans
  `fixedCosts` (l'écran affiche « non calculé à la fermeture ») ; la rouvrir puis la refermer recalcule.

## 4. Lots

| Lot | Contenu |
|---|---|
| **FF1** | drapeau `fixedCost`, catégorie `utilities`, `GET /api/gestion/frais-fixes`, `fixedCosts` et `netAfterFixed` du résultat ; livré le 11/11/2026 |
| **FF2** | variante après frais fixes du simulateur (B1b-2) et de la préparation (P6) : `fixedCosts`, `resultAfterFixed`, `breakEvenAfterFixed`, remarque `FIXED_COSTS_INCLUDED`, chiffres principaux inchangés ; contexte du guide IA : `fixedCosts { total, netAfterFixed, byCategory }` en totaux par catégorie (jamais une ligne ni un libellé), nombres admis ; livré le 11/11/2026 |

Schéma : `gestion_cash_movement.fixed_cost` (Version20261216100000, `scripts/migrate_all_v2_gestion_frais_fixes.sh`).
