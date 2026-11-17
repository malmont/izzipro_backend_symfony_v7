# PLAN PREV — simulateur et pilotage par prévisions (17/11/2026, VALIDÉ par le propriétaire avec ses 8 réponses, sans code)

Place dans la file : APRÈS AG-ASSIST, ESSAI-DONNÉES et AG-3c. Rien n'est codé avant son accord. Règle commune : le serveur calcule
tous les chiffres (jamais l'IA) ; l'IA propose, explique et rédige, ne valide jamais seule ; tout appel d'IA affiche son coût en unités
AVANT d'être lancé ; web et mobile lisent les mêmes routes.

## 0. Ce qui existe et que PREV réutilise

| Brique | Fiche | Ce que PREV en reprend |
|---|---|---|
| P6 « préparer une période » | `docs/preparation-periode.md` | estimation des ventes (an dernier, moyenne, rythme, saisie), plan simulé sans écriture, empreinte `previewHash`, « Valider » (seule écriture), avis du guide |
| B1 budgets par poste + voyages | `docs/budgets-gestion.md` | prévu / réel par poste, voyage avec budget journalier, rattachement des notes et commandes (`tripId`) |
| Simulateur de période (B1b-2) | `docs/simulateur-gestion.md` | leviers prix, promotion, pub, prévu ; ventes restantes, marge, point mort, stock de fin, trésorerie ; aucune écriture |
| Trésorerie (B1b-1) | `docs/tresorerie-gestion.md` | soldes datés, mouvements prévus récurrents, projection, seuil d'alerte |
| Frais fixes (FF) | `docs/frais-fixes-gestion.md` | équivalent mensuel, résultat après frais fixes, point mort |
| Pub (A1) | `docs/publicite-gestion.md` | campagnes, dépense de période, rentabilité perçue avant / après |
| Guide IA (G1) | `docs/guide-ia-gestion.md` | conseils par règles, mesure de l'effet, rédaction vérifiée |
| Année (exercice) | `docs/annee-gestion.md` | mois par mois, cumul, point mort, écart, leviers |
| Console IA (W2) | `docs/console-gestion.md` | analyses nommées, blocs, unités, deux appels au modèle |
| NOTIF | `docs/notifications-courriels.md` | niveaux, plafond de 3 par jour, sujets, « suivi » |

## 1. Les lots, dans l'ordre (petits, chacun livrable seul)

| Lot | Contenu | Réutilise | Schéma |
|---|---|---|---|
| **PREV-1** Simulateur multi-périodes (calcul seul) | `POST /api/gestion/prev/simulation` : durée 1 à 12 mois (ou N périodes) ; entrées : achats de stock datés, voyage DÉTAILLÉ (transport, hébergement, repas, déplacements sur place, stock acheté pendant le voyage), pub (montant, canal, dates), notes de frais, frais fixes (lus de FF, modifiables), promotions prévues (20 / 30 / 50 % / à perte, sur une part du stock, dates) ; sortie MOIS PAR MOIS : ventes, marge, résultat après frais fixes, point mort, stock de fin, trésorerie (projection B1b-1 étendue), avec les hypothèses lues. Aucune écriture. | moteur du simulateur B1b-2 et de P6 appliqué mois par mois ; projection de trésorerie ; frais fixes ; rentabilité A1 pour l'effet de la pub | rien |
| **PREV-2** Plan validé | « Valider » enregistre le PLAN (une version par site, la précédente gardée) et écrit les budgets par poste de chaque période (B1) et le voyage ; relancer + valider REMPLACE le plan validé ; empreinte comme P6 | « Valider » de P6, `PUT /api/periodes/{id}/budget`, voyage B1 | une table `prev_plan` (version, entrées, sorties mois par mois, validé par, à) |
| **PREV-3** Rentabilité ou trésorerie | `POST /api/gestion/prev/comparer` : deux variantes d'une décision (ex. « acheter 4 000 $ de stock » / « ne pas acheter ») sur la durée choisie, côte à côte : trésorerie à M+N, marge, résultat, point mort | PREV-1 lancé deux fois | rien |
| **PREV-4** Suivi en direct | sous chaque compteur réel (notes de frais, voyage, pub, promotions, ventes), « il vous reste X pour rester dans le plan » en vert / orange / rouge, lu du plan validé ; vignette d'accueil | B1 prévu / réel, résultat de période, vignettes | rien |
| **PREV-5** Alertes de dépassement | voyage, notes de frais, promotions au-delà du prévu, ventes en retard sur le plan ; classées « suivi », sujet `management`, dans les niveaux NOTIF et leur plafond ; une par jour et par sujet | NOTIF (NotificationGate), notifications du téléphone | rien (journal existant) |
| **PREV-6** L'IA propose les budgets | bouton « Proposez-moi des budgets » : le SERVEUR fabrique trois scénarios (forte, moyenne, prudente) par PREV-1 avec des leviers différents (pub, prix, promotions, achats) ; l'IA les commente et explique, sans inventer un chiffre ; le gérant choisit, modifie, valide (PREV-2) ; l'IA ne valide jamais | console W2 (blocs, vérification des nombres), guide G1 | rien |
| **PREV-7** « Je peux dépenser X ? » | analyse nommée de la console : le serveur rejoue le plan avec la dépense, rend l'effet (reste, trésorerie, point mort), l'IA rédige | console W2, PREV-1 | rien |
| **PREV-8** Repères publics (option) | le gérant renseigne ses paramètres (§ 3), voit le coût AVANT, puis l'IA cherche des repères sur le web ; affichés comme HYPOTHÈSES, avec leur source et leur date, modifiables ; avec un an d'historique, le serveur montre les deux côte à côte (repère / réel) | client IA existant + outil de recherche web du modèle | une table `prev_benchmark` (paramètres, repères, sources, à) |

| **PREV-9** La console fait le simulateur et répond sur le plan (ajout du propriétaire, 17/11/2026) | analyses nommées de la console (web et mobile) qui LISENT le plan validé, les prévisions et le suivi en direct (« combien me reste-t-il en notes de frais ce mois-ci ? », « suis-je dans les prévisions ? », « pourquoi suis-je en rouge ? ») ; sur une demande en langage courant (« le budget général de l'année avec un voyage d'achat en mars et 500 $ de pub par mois »), l'IA REMPLIT les entrées de PREV-1, le SERVEUR calcule, l'IA présente le résultat, ses suppositions et ce qu'elle propose de changer ; une carte d'action « Confirmer » mène à la validation PREV-2 (rien sans le clic du gérant), trace « par l'assistant, confirmé par X », coût affiché avant ; mêmes règles qu'IA-A | console W2 (analyses nommées, blocs, nombres vérifiés), fils d'action (carte à confirmer), PREV-1 à PREV-4 | rien |

Ordre : PREV-1, PREV-2, PREV-3, PREV-4, PREV-5, PREV-6, PREV-7, PREV-8, PREV-9 (les quatre premiers sans IA : les chiffres d'abord ; PREV-9 après PREV-6 et 7, dont il reprend les analyses).

## 2. Paramètres des repères publics (PREV-8)

Ville et province, type de quartier (centre-ville, rue commerçante, centre commercial, quartier résidentiel, zone touristique), secteur
(liste fermée), vente en ligne (oui / non, part estimée), application mobile, panier moyen (lu du site s'il existe), saisonnalité (mois
forts et creux), surface (m²), nombre d'employés, années d'existence, fourchette de prix (entrée / milieu / haut de gamme), part de
clientèle touristique.

## 3. Coût en unités (proposition)

| Geste | Unités |
|---|---|
| Simulation, comparaison, suivi, alertes (PREV-1 à 5) | 0 (calcul du serveur) |
| « Proposez-moi des budgets » (PREV-6 : trois scénarios commentés) | 2 |
| « Je peux dépenser X ? » (PREV-7) | 1 (comme une réponse de la console) |
| Repères publics (PREV-8 : recherche web + synthèse) | 3, affichés avant ; une fois par mois au plus par site, sauf paramètres changés |
| Question sur le plan à la console (PREV-9 : « combien me reste-t-il… », « pourquoi suis-je en rouge ? ») | 1 (une réponse de la console) |
| Simulation demandée en langage courant (PREV-9 : entrées remplies par l'IA, calcul du serveur, présentation) | 2 (deux appels : lecture de la demande, présentation) ; la validation par « Confirmer » : 0 |

## 4. Questions au propriétaire : TOUTES VALIDÉES le 17/11/2026 telles que proposées

1. Durée maximale : 12 mois glissants ou l'exercice ? → 1 à 12 mois glissants, découpés selon les périodes du site.
2. Relancer remplace le plan validé : garder l'ancien en historique ? → oui, versions gardées 24 mois, seule la dernière est suivie.
3. Effet de la pub sur les ventes : d'après la rentabilité perçue A1 du site, sinon une hypothèse saisie ? → A1 si au moins une campagne mesurée, sinon hypothèse affichée et modifiable.
4. Effet d'une promotion : élasticité fixe ou apprise ? → hypothèse par défaut (ex. -20 % de prix → +30 % d'unités), modifiable, apprise des promotions passées du site quand il y en a.
5. Les alertes de dépassement : courriel, téléphone, ou les deux ? → les deux, niveau « suivi », dans le plafond NOTIF.
6. Repères publics : un fournisseur de recherche web (l'outil de recherche du modèle) est-il accepté, avec les sources affichées ? → oui, sources citées, aucune donnée du site envoyée hors ville, secteur et paramètres choisis.
7. Le mobile a-t-il les mêmes écrans dès PREV-1 ? → oui, mêmes routes ; contrat des réponses remis au mobile et au web à chaque lot.
8. Les trois scénarios de PREV-6 : bornes fixes (forte = +20 % de pub…) ou demandées au gérant ? → bornes par défaut affichées, modifiables avant de lancer.
