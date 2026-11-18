# Plan du lot IMPOTS : impôts, salaires, prêts et aides dans la gestion

> 18/11/2026, session « prevention des pertes backend », à la demande de « new easymakemoney ». Cadre validé par le propriétaire le
> 17/11/2026 ; **décisions du propriétaire du 18/11/2026 au § 6** (Floride d'abord, tout dans le module de gestion). Lecture et rédaction seulement, à partir de `dev` en eb549ca9 : **aucun code**. À faire valider par le chef, puis par le
> propriétaire.
>
> **Ce sont des ESTIMATIONS, pas un conseil fiscal.** Chaque chiffre produit par ce lot porte la mention « estimation » et renvoie à un
> texte de prudence que l'avocat doit valider (§ 6, question Q9). Les taux viennent d'une table tenue par la plateforme et validée par un
> comptable du pays. Le commerçant peut ajuster chaque taux pour son cas.

## 0. Ce que le propriétaire veut

1. **En un seul endroit**, les impôts et taxes que l'entreprise risque d'avoir à payer, selon son pays ou sa région.
2. **Le coût employeur ESTIMÉ des salaires** (brut et charges), sans bulletin de paie officiel.
3. **Les prêts avec intérêts** :
   - une fiche du prêt (montant, taux, durée, date, différé) produit son échéancier ;
   - chaque échéance a deux parts : les intérêts et l'assurance vont en charges (rentabilité et trésorerie), le capital seulement en
     trésorerie ;
   - le capital reçu entre en trésorerie, jamais en chiffre d'affaires.
4. **Les aides**, avec leurs conditions et justificatifs suivis :
   - une aide d'exploitation est un produit ;
   - une aide à l'investissement est un encaissement ;
   - une avance remboursable est un prêt à 0 %.
5. **Des taux par pays ou région** dans une table tenue par la plateforme, datée, et ajustable par le commerçant.
6. Le tout **pris en compte** dans les résultats, la trésorerie et le simulateur PREV (`docs/plan-prev.md`).

Les lots s'appellent **FIS-1 à FIS-8**, pour ne pas les confondre avec les lots IMP de l'import d'une boutique
(`docs/agences-et-import.md`).

## 1. Ce qui existe et que le lot réemploie

| Brique | Ce qu'elle fait aujourd'hui | Référence |
|---|---|---|
| Mouvements prévus (`gestion_cash_movement`) | Libellé, catégorie (`rent`, `salaries`, `insurance`, `subscription`, `utilities`, `loan`, `tax`, `other`), sens, montant en cents, début, récurrence (`none`, `weekly`, `monthly`, `quarterly`, `yearly`), fin, case « frais fixe ». Occurrences **calculées**, jamais stockées. Une occurrence passée est supposée payée | `GestionCashMovement.php:18-22` ; `GestionCashService::occurrences()` l. 225-273 ; `docs/tresorerie-gestion.md` |
| Projection de trésorerie | Somme des occurrences par jour, `byCategory`, `cashUntil()` (lu par le simulateur), `plannedBetween()` (lu par le rapprochement). Remarque `TAXES_INCLUDED` toujours affichée : un rappel manuel de prévoir les taxes à reverser | `GestionCashService.php:320-433, 630-643, 392` |
| Frais fixes (FF1) | Équivalent mensuel (hebdomadaire × 52/12, mensuel × 1, trimestriel ÷ 3, annuel ÷ 12), prorata journalier dans une période. Ils entrent au résultat **à côté** du net : `netAfterFixed = net − fixedCosts.total`, sans changer `net` | `GestionFixedCostService.php:20-127` ; `PeriodResultCalculator.php:80-82, 104-106` |
| Résultat de période | Ventes HT, coût, marge, frais variables (`expenses.shipping`, `notes`, `other` = la publicité), `net`, `netAfterFixed`. **Figé à la fermeture** (`period_result`) : une période fermée avant un lot garde son instantané | `PeriodResultCalculator::compute()` l. 50-109 ; `docs/periodes-gestion.md` |
| Année et exercice | Mois par mois, cumul, projection du reste de l'exercice, leviers (dont le poids des frais fixes), bloc « exercice terminé » | `GestionYearService.php` (`block` l. 248, `compose` l. 278, projection l. 333-435, leviers l. 525-570, `finishedBlock` l. 579) |
| Simulateur (B1b-2) | Leviers prix, promotion, publicité, prévu. `cashAfterPurchases` part de `cashUntil` : les mouvements `tax`, `loan` et `salaries` y sont déjà comptés en trésorerie | `GestionSimulationService.php:45-219` ; `docs/simulateur-gestion.md` |
| PREV (plan validé, sans code) | Simulation sur 1 à 12 mois, plan validé et versions, trois scénarios, « je peux dépenser X ? », repères publics avec le nombre d'employés | `docs/plan-prev.md` (PREV-1 l. 26, PREV-8 l. 43) |
| Profil pays | **Absent** : pays en texte libre, identifiants fiscaux, exercice. Tables proposées et non codées `site_tax_profile` et `tax_rule_package` (taux en millionièmes, échéances, comptes) | `docs/pays-audit.md` § 1.2 et § 7 (PAYS-2, PAYS-3) |
| Tables de la base maître tenues par la plateforme | Modèles à suivre : `loss_hardware_products`, `platform_offers`, `platform_settings` | `src/LossPrevention/Master/LossHardwareProductRepository.php` |
| Politique par pays en constantes | Modèle de règles par pays et région | `LossCountryPolicy.php:17-54` |
| Salaires masqués | Les salaires sont regroupés en une ligne pour un lecteur sans `ROLE_ADMIN` ; le guide IA ne reçoit que des totaux | `GestionCashService.php:143-170` ; `docs/frais-fixes-gestion.md:49` |

**Ce qui n'existe pas** (relevé du 18/11/2026) :
- Aucune trace de prêt avec échéancier, de salaire ou de charges employeur, d'aide à l'entreprise, ni de table de taux d'impôts.
- Les subventions de la Boussole ESG (`esg_subsidy_program`) concernent la certification, pas les aides de l'entreprise.

**Le « dossier de fin d'exercice » qui citerait emprunts et taxes est introuvable.** Je l'ai cherché dans `docs/`, `src/`, `translations/`
et l'historique git. Ce qui s'en approche le plus :
- le bloc « exercice terminé » de l'année, sans impôts ni prêts ;
- la séquence `fiscal_year.close`, dont l'acte de clôture est renvoyé à un lot CLO futur (`docs/sequences-gestion.md:44`) ;
- le bilan d'année en PDF (DOC-2).

S'il existe dans un autre dépôt (l'application de gestion), il faut me le montrer (question Q1).

## 2. Principes

### 2.1 Une seule trésorerie, des sources nouvelles

- Les objets nouveaux (prêt, poste salarié, impôt estimé, aide) produisent des **occurrences calculées**, comme les mouvements prévus,
  jamais stockées.
- Une **interface commune de sources de flux** donne à la projection, au rapprochement, au résultat, à l'année et au simulateur la même
  liste d'occurrences. Chaque occurrence porte : date, montant, sens, catégorie, source (`movement`, `loan`, `payroll`, `tax`, `aid`) et
  **deux parts** :
  - `cash`, qui va à la trésorerie ;
  - `result`, la part qui va au résultat, nulle pour le capital d'un prêt.
- Les mouvements saisis à la main restent tels quels.
- **Double comptage** : si un mouvement manuel `loan`, `salaries` ou `tax` existe à côté d'un prêt, d'un poste ou d'un impôt calculé, une
  remarque le signale, sur le modèle de `FIXED_COSTS_MAY_OVERLAP_NOTES`. Le gérant choisit lequel garder, et le serveur ne supprime rien.

### 2.2 Le résultat : des lignes à côté, `net` inchangé

- On suit le modèle de FF1 : un bloc `charges` à côté du net, et non dans `expenses.other`, pour garder la règle « total égal au cent »
  des statistiques.
- Le bloc `charges` contient :
  - `financial` : intérêts et assurance des prêts ;
  - `payroll` : coût employeur estimé ;
  - `taxes` : impôts et taxes estimés, hors taxes collectées à reverser ;
  - `operatingAids` : aides d'exploitation, en produit.
- Il en sort `netAfterCharges = netAfterFixed − financial − payroll − taxes + operatingAids`.
- Une **période fermée avant le lot garde son instantané**, avec une remarque « clôturée avant le lot », comme FF1.
- Les salaires comptés à la fois comme frais fixes (mouvement `salaries` coché) et comme postes estimés sont signalés. Quand un poste
  existe, la règle par défaut ne les compte qu'une fois.

### 2.3 La trésorerie

| Objet | Trésorerie | Résultat |
|---|---|---|
| Capital d'un prêt reçu | entrée, à la date de déblocage | rien (jamais du chiffre d'affaires) |
| Échéance d'un prêt | sortie du total (capital + intérêts + assurance), à sa date | intérêts et assurance seulement |
| Poste salarié | sortie du coût employeur, au jour de paie | coût employeur (au prorata, comme les frais fixes) |
| Impôt estimé | sortie à chaque échéance de paiement (acomptes, solde) | la charge de l'exercice, au prorata |
| Taxes collectées à reverser (TVA, TPS/TVQ, sales tax) | sortie à l'échéance de déclaration | **rien** : ce n'est pas une charge |
| Aide d'exploitation | entrée, à sa date de versement | produit (au prorata de la période qu'elle couvre, sinon à sa date) |
| Aide à l'investissement | entrée, à sa date de versement | rien (simplification, § 5) |
| Avance remboursable | entrée du montant ; sorties aux remboursements | rien : un prêt à 0 % |

### 2.4 Les taux : une table de la plateforme, ajustable par le commerçant

- **Base maître, `fiscal_rate`**, tenue par la plateforme. Une ligne par pays, région, nature et période de validité :
  - `country`, `region` (ISO 3166-2, vide = tout le pays), `kind`, `code`, `label`, `rate_ppm` (taux en millionièmes) ou `brackets`
    (tranches en JSON) ;
  - `base` (bénéfice, brut, chiffre d'affaires, valeur locative…), `valid_from`, `valid_to` ;
  - `source` (le texte ou la page officielle), `status` (`draft`, `approx` ou `validated`, § 6 Q7), `validated_by` (le professionnel),
    `version`.
- **Natures**, à fixer avec le comptable :
  - impôt sur les bénéfices ;
  - charges patronales (globales ou détaillées) ;
  - taxes locales (CFE, taxe foncière, taxes municipales) ;
  - taxes sur les salaires ;
  - taux des acomptes et leur calendrier.
- **Base du site, `gestion_fiscal_override`** : le commerçant ajuste un taux pour son cas (code, taux, motif, auteur, date). L'écran
  montre toujours « taux de la plateforme » et « ajusté par vous ».
- **Chaque estimation garde la version des taux qu'elle a utilisée**, pour qu'un chiffre passé ne change pas en silence.
- **Le profil du site** fournit le pays, la région, la forme juridique et le régime :
  - c'est le profil pays de PAYS-2 (`site_tax_profile`) ;
  - tant qu'il n'existe pas, FIS-3 lit le pays et la région de l'adresse et demande au gérant de les confirmer, ainsi que la forme
    juridique (question Q4).
- **Aucun chiffre de taux n'est écrit dans ce plan** : la table se remplit avec le comptable de chaque pays (Q2).

### 2.5 Données personnelles des salariés

- Un poste se décrit **sans nom** : « Vendeuse temps partiel », « Responsable de boutique ».
- Champs : le brut, le temps de travail, le contrat, les dates d'entrée et de sortie prévues.
- Il est réservé à `ROLE_ADMIN`, et regroupé en une ligne pour les autres lecteurs (règle existante des salaires).
- Jamais envoyé à l'IA autrement qu'en totaux.
- Aucun bulletin de paie, aucune déclaration sociale : l'écran le dit.

## 3. Les objets

### 3.1 Prêt (`gestion_loan`, base du site)

**Champs**
- libellé, prêteur (texte libre), montant (cents), devise ;
- taux annuel (millionièmes), durée en mois, date de déblocage, date de la première échéance ;
- fréquence : mensuelle ou trimestrielle ;
- type : échéances constantes (par défaut) ou capital constant ;
- différé : durée en mois et genre, `total` (rien n'est payé, les intérêts s'ajoutent au capital) ou `partial` (les intérêts seuls) ;
- assurance : montant fixe par échéance, ou taux sur le capital initial ou restant dû ;
- frais de dossier : charge à la date de déblocage ;
- notes ; lien facultatif vers une aide (avance remboursable).

**Échéancier calculé (jamais saisi)**
- Pour chaque échéance : date, capital, intérêts, assurance, total, capital restant dû.
- L'arrondi au cent est porté par la **dernière** échéance, pour que la somme des capitaux égale exactement le montant.
- Un **remboursement anticipé** se saisit comme un événement daté ; l'échéancier est recalculé à partir de cette date.

**Routes**
- `GET`, `POST`, `PATCH` et `DELETE /api/gestion/prets` ;
- `GET /api/gestion/prets/{id}/echeancier` ;
- `POST /api/gestion/prets/apercu` : l'échéancier sans rien écrire.

### 3.2 Poste salarié (`gestion_payroll_position`, base du site)

**Champs** (sans nom) :
- libellé ;
- brut mensuel ou annuel, nombre de paies par an (12, 13, 26, 52…) ;
- heures par semaine (facultatif) ;
- genre de contrat (code de la table, par exemple cadre ou non-cadre en France) ;
- date d'entrée, date de sortie (facultative) ;
- jour de paie dans le mois.

**Calcul** :
- coût employeur estimé = brut × (1 + taux des charges patronales du pays et du genre de contrat), plus les taxes sur salaires de la
  table ;
- le détail par cotisation n'est montré que si la table le donne ;
- les allègements (réductions générales, crédits d'impôt à l'embauche) ne figurent qu'en taux ajusté par le commerçant, avec un motif.

**Routes** : `/api/gestion/postes` et `GET /api/gestion/postes/apercu`.

### 3.3 Impôts estimés (calculés, pas d'objet saisi)

- **Impôt sur les bénéfices** :
  - taux ou tranches du pays, appliqués au résultat estimé de l'exercice : `netAfterCharges` réel jusqu'à aujourd'hui, plus la
    projection du reste (`GestionYearService`) ;
  - les acomptes suivent le calendrier de la table ;
  - si la forme juridique fait peser l'impôt sur la personne (entreprise individuelle, micro-entreprise, entreprise non incorporée),
    l'impôt n'est **pas estimé** et l'écran le dit (Q4).
- **Taxes locales et forfaitaires** : un montant ou un taux sur une base que le commerçant saisit (valeur locative, surface), à l'échéance
  de la table.
- **Taxes collectées à reverser** :
  - les taxes collectées de la période (section `taxes` des statistiques) moins les taxes récupérables ;
  - tant que PAYS-5 n'enregistre pas les taxes sur les achats, le montant à reverser est **surestimé**, et marqué comme tel ;
  - échéances selon la fréquence de déclaration du profil.
- **Le tableau « Impôts et taxes »** (le « seul endroit » demandé) : chaque nature, son montant estimé de l'exercice, ses prochaines
  échéances, sa source (taux de la plateforme ou ajusté) et sa mention « estimation ».

### 3.4 Aide (`gestion_aid`, base du site)

**Champs**
- libellé, organisme ;
- genre : `operating` (exploitation), `investment` (investissement) ou `refundable_advance` (avance remboursable) ;
- montant accordé ; versements prévus et reçus (dates, montants) ;
- période couverte, pour une aide d'exploitation ;
- conditions : une liste d'engagements, chacun avec une échéance et un état (`à faire`, `tenu`, `non tenu`) ;
- justificatifs : documents de la gestion déjà existants (`GestionDocument`), rattachés ;
- état : `requested`, `granted`, `received`, `to_repay` ou `closed`.

**Effets**
- **Exploitation** : produit au résultat, au prorata de la période couverte (sinon à la date du versement), et entrée en trésorerie au
  versement.
- **Investissement** : entrée en trésorerie seulement.
- **Avance remboursable** : elle crée un prêt à 0 % (§ 3.1), avec son échéancier de remboursement.
- **Rappel** : une condition non tenue à son échéance, ou un justificatif manquant, passe par les notifications de la gestion
  (`docs/notifications-gestion.md`) et par le guide.

## 4. Les lots (petits, chacun livrable seul)

| Lot | Contenu | Dépend de | Risque |
|---|---|---|---|
| **FIS-0** (décisions, sans code) | Réponses du § 6 ; texte de prudence validé par l'avocat ; comptable référent par pays prioritaire | rien | — |
| **FIS-1** Prêts | Fiche, échéancier calculé (échéances constantes et capital constant, différés, assurance, frais, remboursement anticipé), aperçu. Interface commune des sources de flux. Le prêt dans la projection et le rapprochement ; intérêts et assurance dans le bloc `charges.financial` du résultat de période, de l'année et des statistiques. Remarque de double comptage avec les mouvements `loan` | FIS-0 (Q3) | moyen (arrondis, période figée) |
| **FIS-2** Aides | Les trois genres, conditions, justificatifs, rappels ; l'avance remboursable par FIS-1 ; `charges.operatingAids` | FIS-1 | faible |
| **FIS-3** Taux | Table `fiscal_rate` de la base maître et son écran (super administrateur), table d'ajustement du site, confirmation du pays, de la région et de la forme juridique tant que PAYS-2 n'existe pas, version des taux dans chaque estimation | FIS-0 (Q2, Q4, Q7) | moyen (taux périmés, validation) |
| **FIS-4** Salaires estimés | Postes sans nom, coût employeur, dans la trésorerie et `charges.payroll` ; règle avec les mouvements `salaries` et les frais fixes | FIS-3 | moyen (données personnelles, double comptage) |
| **FIS-5** Impôts estimés et tableau « Impôts et taxes » | Impôt sur les bénéfices sur le résultat estimé de l'exercice, acomptes, taxes locales, taxes collectées à reverser (surestimées sans PAYS-5), `charges.taxes`, le tableau unique | FIS-3, FIS-1, FIS-4 | élevé (le chiffre qui ressemble le plus à un conseil fiscal) |
| **FIS-6** Simulateur et année | Leviers « emprunter X sur N ans », « embaucher un poste à Y », « recevoir une aide » dans le simulateur actuel et dans la projection de l'année ; leviers de l'année chiffrés après charges | FIS-1 à FIS-5 | faible |
| **FIS-7** PREV | Les sources de flux et le bloc `charges` dans les entrées de PREV-1 (modifiables par scénario), dans le suivi « il vous reste » et dans « je peux dépenser X ? » | PREV-1, FIS-6 | faible |
| **FIS-8** Échéances | Rappels des échéances fiscales et des prêts par les notifications de la gestion ; rejoint PAYS-7 | FIS-5, NOTIF | faible |

- **Ordre recommandé** : FIS-0, puis FIS-1 (le plus concret, sans taux ni risque fiscal), FIS-2, FIS-3, FIS-4, FIS-5, FIS-6, FIS-7, FIS-8.
- **Chaque lot** apporte, en plus de son code :
  - ses termes au lexique de la gestion (capital restant dû, intérêts, coût employeur, acompte, avance remboursable…) ;
  - ses guides d'écran ;
  - une analyse nommée pour la console.

## 5. Risques

- **Conseil fiscal** :
  - un chiffre d'impôt ou de charges peut être pris pour un avis ;
  - parades : mention « estimation » sur chaque chiffre, texte validé par l'avocat, taux datés avec leur source et leur validateur,
    « ajusté par vous » visible ;
  - aucun chiffre n'est présenté comme « à payer », seulement comme « estimé ».
- **Formes juridiques et régimes** : l'impôt dépend de la forme (société, entreprise individuelle, micro-entreprise) et du régime. Sans
  forme confirmée, l'impôt sur les bénéfices n'est pas estimé.
- **Double comptage** avec les mouvements manuels `loan`, `salaries` et `tax`, et avec les frais fixes : remarques et règle de priorité
  (§ 2.1, § 2.2).
- **Périodes figées** : une période fermée avant le lot ne gagne pas les nouvelles lignes ; la remarque le dit.
- **Arrondis** : la somme des échéances doit égaler le montant et les intérêts au cent ; la dernière échéance porte l'écart, et les essais
  le vérifient.
- **Taux périmés** : chaque taux a une période de validité. Une estimation qui tombe hors de toute période porte une remarque et utilise le
  dernier taux connu.
- **Simplifications comptables** :
  - l'aide à l'investissement n'est pas étalée sur la durée du bien ;
  - les intérêts sont pris au prorata, pas à leur exigibilité ;
  - ces écarts avec la comptabilité réelle sont écrits dans la fiche et dans l'aide.
- **Données personnelles** : un poste salarié peut désigner une seule personne même sans nom. Lecture réservée à l'administrateur,
  regroupée ailleurs, jamais envoyée à l'IA en détail.
- **Plusieurs devises** : un prêt ou une aide dans une autre devise que le site garde sa devise ; la conversion est celle de la gestion
  (taux saisi), sans cours automatique.

## 6. Décisions du propriétaire (18/11/2026, relayées par le chef)

- **Q1, dossier de fin d'exercice** : c'est un cahier des charges du dépôt de l'application MAUI (`docs/web/dossier-fin-exercice.md`), pas
  encore codé (lot CLO). Il montre :
  - une ligne « Emprunts » : par emprunt, le prêteur, le capital emprunté, le capital restant au début et à la fin de l'exercice et les
    intérêts de l'exercice ; un remboursement de capital n'est pas une charge, les intérêts le sont ;
  - une ligne « Taxes » : des totaux par taxe, perçues sur les ventes, payées sur les achats et les frais, et le solde (TPS, TVQ, TVH par
    province ; sales tax par État) ;
  - l'export pour le comptable reprend emprunts et taxes.

  **CLO produira le dossier à partir de FIS-1 à FIS-5.** FIS-1 doit donc pouvoir rendre, pour un exercice, le capital restant au début et
  à la fin et les intérêts de l'exercice, par prêt.
- **Q2, premier terrain : la boutique du propriétaire en FLORIDE.** La table des taux commence par la Floride, puis le Québec et la France.
  Elle gère donc le pays, l'**État** et le **comté**, et des **plafonds par salarié** (une base annuelle plafonnée, par exemple les
  7 000 $ de salaire des cotisations chômage). Pour la Floride :
  - la **sales tax** de l'État, plus la **surtaxe discrétionnaire du comté** ;
  - les **charges employeur fédérales** (Social Security, Medicare, FUTA) et le **reemployment tax** de Floride ;
  - l'**impôt des sociétés** : fédéral, et Floride pour une C-corp.

  Conséquences sur le plan :
  - `fiscal_rate` gagne `county` (code du comté, vide = tout l'État) et `wage_base_cents` (plafond annuel de la base par salarié, vide =
    sans plafond) ;
  - le coût employeur d'un poste (§ 3.2) applique chaque taux à la part du brut annuel encore sous le plafond ; le plafond est donc
    calculé sur l'année civile du poste ;
  - la sales tax collectée à reverser (§ 3.3) se calcule par comté quand la vente porte l'adresse du comté.
- **Q3 à Q7 et Q9** : les recommandations sont retenues :
  - **Q3** : intérêts et salaires au prorata au résultat ;
  - **Q4** : la forme juridique est demandée. Aux États-Unis : **LLC, S-corp ou C-corp**. L'impôt qui pèse sur la personne n'est pas
    estimé (LLC imposée comme une entreprise individuelle ou une société de personnes, S-corp) : seule la C-corp a son impôt des
    sociétés estimé ;
  - **Q5** : postes sans nom ;
  - **Q6** : l'aide à l'investissement est un encaissement seul ;
  - **Q7** (précision du propriétaire) : **on n'attend pas le comptable pour démarrer**. Un taux a trois états :
    - `draft` : en cours de saisie, **jamais servi** ;
    - `approx` : valeur APPROXIMATIVE saisie par le super administrateur, servie aux sites. Tout montant calculé avec au moins un taux
      `approx` porte la mention visible « Taux approximatif, à faire valider par un professionnel » (un champ `approximate: true` dans
      chaque réponse, et la mention rédigée par le serveur) ;
    - `validated` : validé par un professionnel (son nom et la date).

    Les taux de la Floride viennent en premier, à l'état `approx`. Les salaires et les impôts seront validés plus tard par un
    professionnel ; d'ici là, même état et même mention. Chaque estimation garde la version des taux qu'elle a utilisée ;
  - **Q9** : texte de prudence à faire valider par l'avocat.
- **Q8** : **tout est compris dans le module de gestion** (prêts, aides, taux, salaires, impôts estimés). Son prix sera fixé plus tard ; pas
  d'offre supérieure.
- **Calendrier** : le code attend la fin d'AIDE-APP et celle du multi-boutiques chez le chaperon. Le contrat de FIS-1 (prêts) sera
  proposé au chef à ce moment.

## 7. Questions au propriétaire (avec ma recommandation)

- **Q1. Le « dossier de fin d'exercice » qui citerait emprunts et taxes : lequel ?** Je ne le trouve ni dans ce dépôt ni dans son
  historique. Recommandation : s'il n'existe pas encore, le lot CLO (clôture de l'exercice) le produira à partir de FIS-1 à FIS-5.
- **Q2. Pays et régions prioritaires pour la table des taux ?** Recommandation : Québec (Canada) et France métropolitaine d'abord, avec un
  comptable chacun ; États-Unis ensuite.
- **Q3. Au résultat d'une période, intérêts et coût des salaires au prorata ou à leur date ?** Recommandation : au prorata, comme les
  frais fixes, pour des périodes comparables entre elles. La trésorerie, elle, reste à la date.
- **Q4. La forme juridique et le régime d'imposition** : les demander au gérant dans le profil du site, et ne pas estimer l'impôt sur les
  bénéfices quand l'impôt pèse sur la personne ? Recommandation : oui.
- **Q5. Les postes salariés sans nom**, réservés à l'administrateur : d'accord ? Recommandation : oui.
- **Q6. L'aide à l'investissement en encaissement seul** (simplification de la demande), sans étalement comptable ? Recommandation : oui,
  avec la simplification écrite dans l'aide.
- **Q7. Qui tient la table des taux ?** Recommandation : le super administrateur la saisit et un comptable la valide (état « validé » et
  son nom). Un taux « brouillon » n'est jamais servi aux sites.
- **Q8. Offre** : le lot fait-il partie du module de gestion, ou d'un module payant à part ? Recommandation : dans le module de gestion.
  Les prêts et les aides se servent à tous ; les estimations d'impôts et de salaires peuvent être réservées à une offre supérieure.
- **Q9. Le texte de prudence** affiché avec chaque estimation : à rédiger avec l'avocat. Proposition de départ : « Estimation indicative
  calculée à partir des taux connus de la plateforme et de vos réglages. Ce n'est pas un conseil fiscal ni social : faites-la vérifier
  par votre comptable. »
