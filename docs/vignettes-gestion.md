# Vignettes d'accueil — application de gestion (première version, 11/11/2026)

Contrat validé par l'application (« new easymakemoney ») : premiers pas par règles et alerte de stock bas seulement. Les autres alertes
(fonds de roulement, dépenses au-dessus du prévu, budget journalier du voyage) viendront avec B1, les conseils formulés par l'IA (`advice`)
avec G1, sans changer la forme. `ROLE_ADMIN` (`access_control` `/api/gestion`). Code : `Services/GestionService/HomeTilesService`,
`UseCase/GestionUseCase/HomeTilesUseCase`, `Controller/GestionController/HomeTilesController`, `Entity/GestionTileDismissal`
(`gestion_tile_dismissal`, `Repository/GestionTileDismissalRepository`), textes dans `translations/gestion.{fr,en}.yaml` (`tiles.*`).
Migration `Version20261205100000`, script `scripts/migrate_all_v2_gestion_vignettes.sh`. Test : `tests/Functional/Gestion/HomeTilesApiTest.php`.

## 1. Routes

| Route | Rôle |
|---|---|
| `GET /api/gestion/accueil/vignettes?locale=fr\|en` | 3 vignettes au plus pour l'utilisateur connecté, les plus urgentes d'abord ; `Cache-Control: private, no-store` |
| `POST /api/gestion/accueil/vignettes/{id}/ecarter` | 204 ; 404 `TILE_NOT_FOUND` pour un identifiant inconnu |

## 2. Vignette

`{ id, kind: "first_steps" | "alert", priority, title, text, action: { screen, params }, sources: [{ label, value }], dismissible, figures }`

- `priority` de 1 à 100, plus haut = plus urgent ; `title` et `text` prêts à afficher dans la langue demandée (`?locale=`, sinon langue du site).
- `action.screen` : code d'écran que l'application traduit en navigation ; `dismissible` toujours vrai.

| id | kind | priorité | condition | écran |
|---|---|---|---|---|
| `cash-low` | alert | 85 | point bas de trésorerie des 14 prochains jours sous le seuil (lot B1b-1, `docs/tresorerie-gestion.md` § 5) | `tresorerie` `{}` |
| `advice` | advice | 68 / 64 / 60 | premier conseil du guide de la période en cours, selon sa confiance ; jamais trésorerie ni voyage (lot G1a, `docs/guide-ia-gestion.md` § 6) | `conseils` `{ adviceId }` |
| `low-stock` | alert | 80 | au moins un article qui se vend va manquer (§ 3) | `stock.low` `{ variantIds }` |
| `trip-budget` | alert | 75 | voyage en cours au-delà de son budget à date (lot B1a, `docs/budgets-gestion.md`) | `voyages.suivi` `{ tripId }` |
| `budget-over` | alert | 70 | un poste du budget de la période en cours dépasse le prévu (lot B1a) | `periode.budget` `{ periodId }` |
| `first-invoice` | first_steps | 40 | aucune ligne d'achat et aucune facture confirmée | `documents.scan` `{ type: "facture" }` |
| `first-note` | first_steps | 30 | aucune note de frais | `documents.scan` `{ type: "note" }` |
| `cash-first-balance` | first_steps | 20 | aucun solde de trésorerie saisi, et un mouvement prévu ou une période en cours (lot B1b-1) | `tresorerie` `{}` |
| `first-campaign` | first_steps | 10 | aucune campagne de publicité | `campagnes.new` `{}` |

Un premier pas disparaît seul une fois l'action faite.

## 3. Stock bas

- Variante vendue au moins 2 fois (nettes des retours) sur les 30 derniers jours, dont le stock couvre moins de 14 jours de ventes à ce rythme
  (`stock × 30 < 14 × vendus`) ; un stock nul ou négatif compte. Constantes de `HomeTilesService` (réglables plus tard avec B1).
- Seuls les articles en vente : type standard, vente active, pas de location, visibles en boutique ou en caisse (un article créé par une
  facture reste invisible jusqu'à son activation). Locations (réservations), abonnements et lignes libres (cartes-cadeaux) exclus.
- `action.params.variantIds` : toutes les variantes concernées ; `figures` : les 20 plus urgentes,
  `{ productId, variantId, name, stock, soldLast30Days, daysLeft }` ; `sources` : ventes des 30 derniers jours et stock actuel (sommes).

## 4. Écarter

Par utilisateur. Un premier pas écarté ne revient plus (`until` null). Une alerte écartée revient au bout de 7 jours, ou plus tôt si la liste
des variantes concernées change (`signature` = empreinte de la liste au moment d'écarter).

Les alertes urgentes partent aussi en notification sur téléphone, avec un seuil plus strict : `docs/notifications-gestion.md`.
