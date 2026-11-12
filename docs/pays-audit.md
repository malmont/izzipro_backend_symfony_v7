# Audit de conformité par pays (lot PAYS) : constat et propositions

> 12/11/2026, session « appli degestion backend », à la demande de « new easymakemoney ».
> Lecture seule : code de `dev` en 68fcb06a et bases des 8 sites relevées le 12/11/2026 vers 02 h 30 UTC.
> Aucun code ni contrat dans ce lot. Les règles fiscales citées en § 6 et § 7 sont **indicatives** : elles sont à faire
> valider par un comptable du pays avant tout lot.

Décision du propriétaire (« Conformité par pays », 11/11/2026, `docs/etat-fonctions-gestion.md` du dépôt Easymakemoney1) :
la gestion et la comptabilité s'adaptent à la France, au Canada et aux États-Unis. Un **profil pays par site** (pays,
province ou État, régime de taxes) pilote les taxes, le plan de comptes, les exports (FEC en France ; QuickBooks et Sage
ailleurs), les mentions obligatoires et les échéances. Les règles sont des **données datées et versionnées**. Chaque paquet
est validé par un comptable du pays. Pour la France, la facturation électronique et la certification NF525 de la caisse
relèvent du propriétaire.

## 0. Synthèse : dix constats

1. **Il n'existe pas de profil pays.** Le pays vient du texte libre `address_entreprise.country`, et seuls 3 sites sur 8 ont
   une adresse structurée. Au moins trois normaliseurs le relisent, avec des défauts divergents : fuseau UTC ou
   `America/Toronto`, pays US pour Stripe, langue fr, devise CAD.
2. **Les taux de taxe ne sont ni datés ni figés.** La table `tax` stocke le taux en `float`, sans date de validité.
   `order_tax` ne garde que le montant et l'assiette, avec un lien vers la ligne `tax`. Le ticket et le rapport X relisent
   donc le **nom et le taux actuels** : si l'on modifie un taux, la réimpression d'un ancien ticket change. Rien n'empêche
   de modifier ou de supprimer une taxe déjà utilisée.
3. **Quatre sites ont des taxes fausses ou mal formées** : TVQ à 10 % au lieu de 9,975 %, provinces en texte libre
   (« Québec », « Toutes »). Il s'agit de esgboost, karaandb, lintendantprive et memoiresvivantes. Aucun n'a de vente.
4. **Le Québec ne peut pas vendre en prix taxes comprises.** Le garde-fou du mode TTC refuse tout taux qui n'est pas un
   multiple de 0,01 %, et 9,975 % ne l'est pas.
5. **Pour les États-Unis, la table ne gère qu'un taux par État.** Les taxes de comté, de ville et de district ne passent
   que par Stripe Tax. Le seuil d'assujettissement de chaque État (« nexus ») n'est pas suivi.
6. **La France a sa structure, pas ses données ni ses mentions.** Les catégories de TVA (lot F18 2a) et le mode TTC
   existent. Il manque le jeu de taux français, l'autoliquidation, l'impression du SIRET et du détail de la TVA par taux
   par le gabarit serveur du ticket, et les mentions légales imposées.
7. **Le web n'émet pas de facture.**
   - La référence de commande est `REF#` + `uniqid()` : il n'y a pas de numérotation continue.
   - Il n'y a pas de facture PDF.
   - Le courriel de confirmation ne donne ni l'identité du vendeur ni le détail des taxes par taux.
   - Un remboursement web n'a pas d'avoir numéroté.
8. **La caisse a une base solide pour NF525, mais incomplète.**
   - Ce qui existe : journal d'audit chaîné, déclencheur anti-modification, Z numérotés, archive annuelle, conservation.
   - Ce qui manque : signature, empreinte par ticket et par Z, grand total perpétuel, clôtures mensuelles et annuelles
     cumulées, garde de conservation bloquante par défaut, sauvegarde hors serveur.
9. **La comptabilité ne couvre que la caisse.**
   - Exports : CSV générique et QuickBooks Online seulement.
   - Le plan de comptes est québécois par défaut, et le compte de taxe est choisi d'après le libellé de la taxe.
   - Absents : écritures des ventes web, FEC, format Sage natif.
10. **La gestion ignore les taxes des achats.**
    - Notes de frais, commandes fournisseurs et frais de port n'ont aucun champ de taxe, alors que la lecture IA des
      factures les extrait.
    - Pas de taxes récupérables : ni CTI/RTI, ni TVA déductible.
    - Pas de rappel de déclaration.

## 1. Relevé par site

### 1.1 Données des 8 sites (bases au 12/11/2026)

| Site (base) | Adresse structurée (pays / prov.) | Adresse texte | Devise | Langue / fuseau | Prix TTC | Numéros renseignés | Taxes (table `tax`) | Ventes / reçus / journal |
|---|---|---|---|---|---|---|---|---|
| arkanoa-media | CA / QC | Montréal | CAD | ∅ / ∅ | non | TVA intracom., EIN (TPS, TVQ vides) | aucune | 0 / 0 / 0 |
| boussoleesg | aucune | vide | CAD | ∅ / ∅ | non | aucun | aucune | 0 / 0 / 0 |
| demo | CA / QC | Montréal | CAD | ∅ / ∅ | non | TVA intracom., EIN (TPS, TVQ vides) | 7 lignes CA codées (TPS, TVQ 9,975 %, TVH ON/NS/NB-NL-PE, TVP BC-MB/SK) | 71 / 32 / 298 |
| esgboost | aucune | **Moirans 38430 (France)** | **CAD** | ∅ / ∅ | non | aucun (ni SIRET ni TVA) | TPS 5 % « Toutes », **TVQ 10 % « Québec »** | 0 / 0 / 0 |
| gmasuite (modèle) | aucune fiche entreprise | | | | | | 7 lignes CA codées | 0 / 0 / 0 |
| karaandb | aucune | Avenue de la Pépinière | CAD | ∅ / ∅ | non | TVA intracom., EIN | TPS « Toutes », **TVQ 10 %** | 0 / 0 / 0 |
| lintendantprive | aucune | Avenue de la Pépinière | CAD | ∅ / ∅ | non | aucun | TPS « Toutes », **TVQ 10 %** | 0 / 0 / 0 |
| memoiresvivantes | « Canada » / QC (non ISO) | rue Dufresne | CAD | ∅ / ∅ | non | aucun | TPS « Toutes », **TVQ 10 %** | 0 / 0 / 0 |

Réglages communs à tous les sites :
- `commerce.taxProvider = table` partout où la boutique est configurée.
- Vides partout : `locale`, `pos_timezone`, `fiscal_year_start_month`, `accounting_accounts` et `data_retention_years`.
  Les défauts de chaque service s'appliquent donc.
- La table `tenants` de la base maître n'a pas de colonne pays.

Pour esgboost, faute d'adresse structurée, chaque service tombe sur son propre défaut :
- fuseau de la caisse : UTC ;
- fuseau des réservations : Toronto ;
- pays pour Stripe : US ;
- durée de conservation : 10 ans.

### 1.2 Ce qui tient lieu de profil pays, et qui le lit

| Donnée | Champ | Lu par (défaut sans adresse) |
|---|---|---|
| Pays, province ou État | `address_entreprise.country` / `.state` (texte libre, 255 caractères) | `TaxEngine::normalizeAddress` (`src/Services/OrderService/TaxEngine.php:135`) ; `StripeService::resolveTenantCountryCode` (sa propre table, défaut US) ; `match` local de `StoreInfoDto` (`src/Dto/StoreInfoDto.php:36`) ; `SiteLocaleResolver::siteCountry` |
| Fuseau | `entreprise.pos_timezone` (null = déduit) | `StoreClock` (province, puis pays, sinon UTC) ; `BookingClock` (défaut `America/Toronto`) ; aucun lien avec le fuseau des quotas Mémoires Vivantes |
| Langue | `entreprise.locale` (null = déduite) | `SiteLocaleResolver` (Québec → fr, reste du Canada et US → en, sinon fr) ; `EmailLocale` |
| Devise | `entreprise.currency` (CAD, EUR ou USD ; verrouillée à la 1re vente) | `TenantCurrencyProvider`, `SiteCurrencyService` ; arrondi des espèces à 5 ¢ si CAD |
| Prix TTC | `entreprise.prices_include_tax` (figé sur chaque commande) | `TaxEngine`, `TaxInclusiveGuard` |
| Identifiants fiscaux | `gst_number`, `qst_number`, `siret`, `tva_intracommunautaire`, `ein` | `StoreInfoDto` (FR : SIRET + TVA ; CA : TPS + TVQ ; US : EIN ; **pas de TVH, TVP, NEQ ni BN**) |
| Durée de conservation | `data_retention_years` (plancher légal) | `RetentionPolicy` (CA 6 ans, US 7, FR et DROM 10 ; défaut 10) |
| Impression du ticket | `pos_receipt_print_mode` | `PosSettingsService` (sur demande en France : loi AGEC) |
| Exercice | `fiscal_year_start_month` | `GestionFiscalYearService` (aucun défaut par pays : année civile) |
| Plan de comptes | `accounting_accounts` | `ChartOfAccounts` (plan québécois par défaut) |

La console de pilotage expose ces données en lecture : `site.country`, `site.locale`, `site.timezone`, `site.currency` et
`site.prices_include_tax` (`FeatureCatalog`, groupe « localisation »).

## 2. Taxes

### 2.1 Moteur
- **Fournisseur** : le réglage `commerce.taxProvider` vaut `table` (défaut) ou `stripe`. Stripe Tax retombe sur la table
  s'il est indisponible (statut `fallback_table`).
- **Adresse qui sert au calcul** (`OrderTaxAddressResolver`) :
  - web : l'adresse de livraison (taxe au lieu de destination) ;
  - retrait en magasin et caisse : l'adresse de la boutique (taxe au lieu de vente) ;
  - sans adresse : aucune taxe, statut `address_required`.
- **Correspondance en mode table** : seuls le **pays et la province ou l'État** sont comparés. La ville et le code postal
  sont ignorés (`TaxEngine.php:172-187`).

### 2.2 Données des taux
- **Table `tax` par site** : `name`, `rate` (`float`), `type` (texte libre), `province` (« Toutes » ou une liste de codes),
  `country` (défaut CA) et `category`. **Pas de date de validité.**
- **Ce que garde une commande** : la ligne `order_tax` a seulement `tax_id`, `amount` et `base`. Le taux et le libellé ne
  sont pas figés : `PosReceiptDto.php:41-49` et le rapport X relisent la ligne `tax` actuelle.
- **Aucune protection** : `TaxCrudController` n'interdit pas de modifier ou de supprimer une taxe déjà utilisée, et le garde
  de conservation ne protège pas `tax` comme table parente.
- **Données de départ** : uniquement pour le Canada, sur demo et sur le modèle gmasuite (`BoutiqueDemoCatalog.php:34-42`).
  Aucun jeu pour la France ni pour les États-Unis.

### 2.3 Calcul et arrondis
- **Prix hors taxes** : chaque taxe est calculée une seule fois sur son assiette cumulée, pas ligne par ligne. Le montant
  vaut `round(assiette × taux)`, avec un arrondi au demi supérieur. La livraison entre dans l'assiette des taxes sans
  catégorie ou de catégorie `standard`.
- **Prix taxes comprises** : les lignes sont groupées par ensemble de taxes, et la livraison est répartie au prorata. Le
  calcul se fait en entiers (dix-millièmes) ; la dernière taxe d'un groupe porte l'écart d'arrondi (`TaxInclusive.php`).
- **Arrondi des espèces** : 5 ¢ en CAD. L'écart est gardé dans `order.cash_rounding` ; le total fiscal ne change pas.
- **À faire trancher par le comptable** : la règle d'arrondi attendue par pays. En France, une TVA arrondie par ligne et
  une TVA arrondie sur le total par taux ne donnent pas toujours le même montant.

### 2.4 Canada
- **Ce qui existe** :
  - TPS et TVQ calculées en parallèle sur la même assiette, sans taxe sur taxe (c'est la règle depuis 2013) ;
  - TVH et TVP en simples lignes par province ;
  - taux de demo et de gmasuite conformes à ce jour, y compris la TVH de la Nouvelle-Écosse à 14 %.
- **Ce qui manque** :
  - le mode TTC au Québec (constat 4) ;
  - les numéros TVH, TVP, NEQ et BN dans les identifiants du site ;
  - des taux datés, pour les changements à venir ;
  - la correction des 4 sites à TVQ 10 %.

### 2.5 France
- **Ce qui existe** :
  - catégories de produit `standard`, `intermediate`, `reduced`, `super_reduced` et `exempt` (20 / 10 / 5,5 / 2,1 %),
    portées par le produit et figées sur chaque ligne de commande ;
  - assiette gardée par taxe ;
  - mode TTC ;
  - ticket imprimé sur demande (loi AGEC) ;
  - taux des DROM cités dans la doc (`docs/caisse.md:2084`).
- **Ce qui manque** :
  - le jeu de taux français et celui des DROM ;
  - la catégorie au niveau de la variante (elle n'existe qu'au niveau du produit) ;
  - l'autoliquidation (B2B intracommunautaire, importations) ;
  - l'identification du client professionnel (n° de TVA du client) ;
  - les mentions de TVA par taux sur les documents (§ 3).
- **À trancher par le comptable** : le traitement des cartes-cadeaux. Aujourd'hui, la vente d'une carte n'est pas taxée,
  et la taxe est perçue à l'utilisation. Le droit européen distingue les bons « à usage unique », taxés dès l'émission,
  des bons « à usages multiples ».

### 2.6 États-Unis
- **Ce qui existe** :
  - en mode table, une ligne au **taux combiné par État**, plus `exempt` pour les produits non taxés ;
  - avec Stripe Tax, le calcul par juridiction (ville et code postal transmis) et les inscriptions posées par
    `app:boutique:stripe-setup --tax`.
- **Ce qui manque** :
  - taux de comté, de ville et de district en mode table ;
  - règle origine / destination propre à chaque État ;
  - suivi des seuils d'assujettissement par État (nexus économique) ;
  - certificats d'exemption des acheteurs professionnels ;
  - exemptions par catégorie de produit, qui varient selon l'État (vêtements, alimentation).
- **Recommandation** : pour les États-Unis, Stripe Tax plutôt que la table.

### 2.7 Caisse
- **En ligne** : même moteur. `create-multi-payment` passe par `CartQuoteCalculator` puis `TaxEngine`. Les taxes du devis
  sont figées puis écrites par `TaxCalculationService`, qui a un repli « une ligne sans catégorie » s'il n'y a pas de taxes
  en attente.
- **Hors ligne : réimplémentation séparée** (`PosOfflineCalculator`, règles pos-offline v1 à v4, jeu de cas
  `config/pos/offline-cases.json`). Toute nouvelle règle de taxe doit être écrite **dans les deux moteurs** et dans le jeu
  de cas. Le mode hors ligne est coupé sur Stripe Tax.
- **Retours (F2)** : taxe au taux de la vente d'origine, ligne par ligne selon sa catégorie.

### 2.8 Achats (gestion)
- **Aucun champ de taxe** :
  - `NoteDeFrais` n'a que `montant`, un montant TTC ;
  - `FraisDePort` n'a que `price` ;
  - `CommandeLine` n'a que des coûts hors taxes ;
  - `Commande` n'a que la devise et le taux de change.
- **La lecture IA des documents extrait pourtant les taxes** : libellé, taux, montant, et `amountsMode` (HT, TTC ou
  inconnu). Ces données restent dans le JSON de `GestionDocument` ; à la confirmation, seul le montant est enregistré.
- **Taux par défaut du lecteur : 14,975 %** (TPS + TVQ), quel que soit le pays du site (`GestionDocumentReader.php:32`).

## 3. Documents émis

### 3.1 Reçus de caisse (C2, F3)
- **Numérotation** (`pos_receipt`) : suites continues **par poste et par nature** (`ticket`, `credit_note`, `gift_card`),
  numéro attribué sous verrou, sans accesseur d'écriture. Il n'y a pas de suite au niveau du site. Les ventes antérieures
  au 19/10/2026 n'ont pas de numéro.
- **Bloc JSON servi à l'application de caisse** :
  - magasin : nom, adresse, téléphone ;
  - `taxNumbers {gst, qst}` ;
  - `taxNumber` : TVA intracommunautaire, sinon EIN ;
  - `taxIds` selon le pays (FR : SIRET + TVA ; CA : TPS + TVQ ; US : EIN) ;
  - lignes, remises avec motif, taxes avec assiette et taux, paiements ;
  - mention de duplicata.
- **Gabarit serveur** (`templates/pos/_receipt_body.html.twig`), utilisé par la page publique, le PDF et le courriel du
  ticket :
  - il imprime le n° TPS, le n° TVQ et « N° fiscal / TVA » ;
  - il **n'imprime ni `taxIds` (donc ni SIRET ni EIN), ni l'assiette par taux, ni une mention « dont TVA »**.
- **Ticket papier** : il est imprimé par l'application de caisse (dépôt posflutter, non audité ici ; voir
  `docs/conception-a3-nf525.md` de ce dépôt).
- **Mentions légales** : texte libre d'en-tête et de pied de ticket. Rien n'est imposé par le code.

### 3.2 Ventes web
- **Référence** : `REF#` + `uniqid()` (`OrderCreationService.php:23`). Ce n'est **pas un numéro de facture continu**, et
  rien n'est écrit au journal d'audit.
- **Courriel de confirmation** : sous-total, taxes sur **une seule ligne**, total, adresses du client. Il n'y a **ni
  l'identité ni les numéros de taxe du vendeur**.
- **Facture PDF** : non trouvée.
- **Abonnements** : la facture est celle de Stripe Billing (`hostedInvoiceUrl`).

### 3.3 Avoirs et remboursements
- **Annulation d'une vente de caisse** :
  - un avoir est numéroté dans la suite du poste ;
  - mais la commande d'origine est **remise à 0** (totaux et `order_tax.amount`) ;
  - les montants d'origine ne restent que dans l'entrée `REFUND.before` du journal, dans l'avoir, dans les paiements et
    dans le Z.

  Une écriture inverse serait plus sûre pour l'inaltérabilité.
- **Retours F2** : commande de retour en montants négatifs, avoir numéroté.
- **Remboursement web** : **sans avoir numéroté**.
- **Cartes-cadeaux** :
  - seule l'empreinte du code est gardée ;
  - la vente d'une carte est un passif non taxé ;
  - les tickets de cartes ont leur propre suite par poste ;
  - le web n'a pas de cartes-cadeaux.

### 3.4 Mentions : ce qui est exigé, ce qui est présent

Les exigences de cette table sont **indicatives** : la liste exacte est à faire valider par un comptable du pays.

| | France | Canada / Québec | États-Unis |
|---|---|---|---|
| Identité du vendeur | Raison sociale, adresse, SIREN / SIRET, n° TVA intracom. (B2B : forme juridique, RCS…). Ticket serveur : nom et adresse **oui**, SIRET **non**. Web : **non**. | Nom, n° TPS / TVH (et TVQ au Québec) au-delà des seuils de l'ARC (100 $ et 500 $ depuis 2021). Ticket : TPS et TVQ **oui** ; TVH **non**. Web : **non**. | Rien d'exigé au niveau fédéral ; selon l'État. Ticket : EIN dans `taxIds`, **non imprimé** par le gabarit serveur. |
| Numéro unique et continu | Exigé (facture). Caisse : **oui, par poste**. Web : **non**. | Recommandé. Caisse oui ; web non. | Non exigé. |
| Détail des taxes | HT, TVA et TTC **par taux**. Caisse : assiette dans le JSON, **pas imprimée** par le gabarit serveur. Web : **une seule ligne**. | Montant de chaque taxe. Caisse oui ; web une seule ligne. | Taxe de vente séparée (selon l'État). |
| Avoir | Facture rectificative numérotée. Caisse oui ; web **non**. | Note de crédit. Caisse oui ; web non. | Selon l'État. |

### 3.5 Caisse face à NF525

| Exigence (inaltérabilité, sécurisation, conservation, archivage) | Existant | Manque |
|---|---|---|
| Inaltérabilité | Journal `pos_audit_log` chaîné en SHA-256, séquence par site, déclencheur PostgreSQL qui refuse UPDATE, DELETE et TRUNCATE, vérification `GET /api/pos/audit/verify`. Ventes verrouillées dans EasyAdmin. | Signature avec clé. Empreinte par ticket et par Z (aucun champ `hash`). Le déclencheur peut être retiré par un administrateur de la base. Taux de taxe non figés (§ 2.2). Commande d'origine remise à 0 à l'annulation (§ 3.3). |
| Sécurisation | PIN avec HMAC puis hachage et blocage, validations d'un responsable, clé d'idempotence, version de l'application journalisée. | Garde de conservation **en mode `observe` par défaut** (il journalise sans bloquer ; le mode `block` existe). |
| Clôtures | Z figé, numéroté sans trou par poste et par session. | Grand total perpétuel, clôtures journalière, mensuelle et annuelle cumulées. |
| Conservation | Durées légales par pays (FR 10 ans), aucune purge. | Copie hors serveur (restic non installé ; sauvegardes de 14 jours sur le même disque). |
| Archivage | Archive annuelle ZIP (JSON, PDF, `SHA256SUMS`, contrôle du chaînage). | Archive signée. |
| Hors ligne | Chaînage par terminal ; une rupture est signalée sans bloquer. | À examiner avec l'organisme certificateur. |
| Attestation | | Certificat NF525 (organisme agréé) : démarche du propriétaire. |

## 4. Comptabilité

### 4.1 Ce qui existe
- **Export de la caisse (F14)** :
  - routes `GET /api/pos/exports/accounting`, `…/summary.csv` et `…/entries.csv?format=generic|qbo`, plus la page
    `/admin/pos/exports` ;
  - format « generic » : séparateur « ; », virgule décimale ;
  - format « qbo » : journal QuickBooks Online, 1 000 lignes au plus ;
  - jours au fuseau du magasin.
- **Plan de comptes** (`ChartOfAccounts`) : un plan par site, québécois par défaut.
  - Comptes de taxe : TPS 2310, TVQ 2320, TVH 2330, TVP 2340, TVA 2350, autre 2390.
  - Le compte est choisi **d'après le libellé** de la taxe : une sales tax américaine tomberait dans « autre ».
- **`JournalEntryBuilder`** : il prévoit une source « web », mais **elle n'est branchée nulle part**.
- **Achats** : `GET /api/gestion/exports/achats.csv`, **sans colonne de taxe**.
- **Taxes collectées** : `GET /api/taxes/monthly` donne un total par mois, toutes taxes confondues, sur l'année civile.

### 4.2 Ce qui manque
- **FEC (France)** :
  - il faut les 18 colonnes réglementaires (`JournalCode` … `Idevise`) ;
  - des écritures numérotées sans trou avec une date de validation ;
  - le plan comptable général (par exemple 44571 TVA collectée, 44566 TVA déductible) ;
  - **tous** les flux : caisse, web, achats, frais.

  Aujourd'hui, seule la caisse produit des écritures.
- **QuickBooks** : seul l'import de journal QuickBooks Online existe. Il n'y a ni QuickBooks Desktop ni reçus de vente.
- **Sage** : aucun format natif. La doc dit seulement que le format « generic » est fait pour être adapté à Sage 50 et à
  Acomba.

## 5. Gestion (app.management)

- **Périodes** : calendrier saisonnier fixe (hémisphère nord), semestres ou mois. Les noms des périodes sont **en français
  seulement**. Rien ne dépend du pays.
- **Exercice** : le mois de début est réglable, sans défaut par pays.
- **Statistiques, simulateur et préparation** : tout est hors taxes. Les prix TTC sont ramenés au HT ; un taux réel calculé
  sur 90 jours alimente les avertissements `TAXES_EXCLUDED` et `TAXES_ESTIMATED`.
- **Trésorerie** :
  - catégorie `tax` dans les mouvements prévus, entrée ou sortie, avec récurrence ;
  - avertissement `TAXES_INCLUDED` (les encaissements comprennent les taxes, à prévoir en mouvement `tax`) ;
  - rapprochement bancaire sans lien avec le pays.
- **Notes de frais et achats** : taxes absentes (§ 2.8). Les taxes récupérables (CTI et RTI au Québec, TVA déductible en
  France) ne sont donc **pas calculables**.
- **Devise d'achat** : CAD, EUR ou USD, avec taux de change par commande.
- **Fournisseurs** : `Fournisseur.pays` est en texte libre et n'est lu par aucun service fiscal. Il deviendrait utile pour
  l'autoliquidation et les importations.
- **Lecture IA des factures** : son taux par défaut est québécois, quel que soit le pays (§ 2.8).

## 6. Échéances

**Ce qui existe et pourrait alimenter des rappels** :
- les mouvements prévus récurrents de catégorie `tax` (occurrences, `nextDate`) ;
- le passage quotidien des notifications de la gestion à 10 h, heure du magasin (`app:gestion:notifications:envoyer`) ;
- le total mensuel des taxes collectées (`/api/taxes/monthly`).

**Ce qui manque** :
- un calendrier de déclarations par pays ;
- un type de notification `tax` ;
- le montant à déclarer, c'est-à-dire les taxes collectées moins les taxes récupérables : il exige les taxes sur les
  achats.

**Fréquences de déclaration** (indicatives, **à valider par le comptable**) :
- **Canada** : TPS/TVH et TVQ en déclaration annuelle, trimestrielle ou mensuelle, selon le chiffre d'affaires taxable
  (seuils de 1,5 M$ et de 6 M$). Le dépôt se fait en général un mois après la fin de la période, trois mois pour une
  déclaration annuelle.
- **France** :
  - régime réel normal : déclaration CA3 mensuelle ;
  - régime simplifié : CA12 annuelle avec deux acomptes ;
  - franchise en base : pas de TVA ;
  - les dates dépendent du SIREN.
- **États-Unis** : chaque État fixe une fréquence (mensuelle, trimestrielle ou annuelle) selon le volume, et une
  déclaration par État où l'entreprise est inscrite.

## 7. Propositions

### 7.1 Un profil pays daté et versionné (forme des données, à discuter)

**Profil du site** : nouvelle table `site_tax_profile`, une ligne par version ; la ligne active est celle dont l'intervalle
de dates couvre le jour.
- `country` : code ISO 3166-1 alpha-2, obligatoire ;
- `subdivision` : code ISO 3166-2, par exemple `CA-QC`, `US-NY`, `FR-971` ;
- `regime` : `CA_GST_QST` | `CA_HST` | `CA_GST_PST` | `CA_GST` | `FR_VAT` | `FR_VAT_DROM` | `FR_VAT_FRANCHISE` |
  `US_SALES_TAX` ;
- `currency`, `locale`, `timezone` : un seul endroit, qui remplace les défauts divergents ;
- `pricesIncludeTax` ;
- `registrations` : liste de `{ type, number, jurisdiction, validFrom }`, avec pour types : TPS, TVQ, TVH, TVP, NEQ, BN,
  SIREN, SIRET, TVA intracommunautaire, EIN, permis d'un État ;
- `rulePackage` : `{ code, version }` ;
- `validFrom`, `validTo`, `createdBy`.

**Paquet de règles** : nouvelle table `tax_rule_package`, une par pays et par subdivision, versionnée.
- `code` (par exemple `CA-QC`, `FR`, `US-NY`), `version`, `status` (`draft` | `validated`), `validatedBy` (le comptable),
  `validatedAt` ;
- `rates` : liste de `{ taxCode, label, ratePpm, category, base: net | net+shipping, compound, accountKey, validFrom,
  validTo }`. Le taux est un **entier en millionièmes** (TVQ 9,975 % = 99 750), ce qui règle aussi le cas du TTC au Québec ;
- `mentions` : textes obligatoires, par document et par langue ;
- `deadlines` : fréquence, échéance et règle de calcul ;
- `accounts` : plan de comptes par défaut (le plan comptable général pour la France).

**Figer à la vente** : `order_tax` gagne `tax_code`, `label`, `rate_ppm` et `package_version`. Les commandes antérieures,
qui n'ont pas ces champs, gardent la lecture de repli, marquée comme telle.

**Un seul résolveur de pays** : il remplace les trois normaliseurs (`TaxEngine`, `StripeService`, `StoreInfoDto`) et lit
le profil.

### 7.2 Ordre des lots, par risque

| Lot | Contenu | Risque traité | Dépend de |
|---|---|---|---|
| **PAYS-1** (livré le 12/11/2026, `docs/caisse.md` « Taxes gelées à la vente ») | Figer taux, libellé et code de la taxe sur `order_tax`. Interdire de modifier ou de supprimer une taxe déjà utilisée : un nouveau taux se crée avec une date. Réimpression fidèle. | Ticket réimprimé faux, inaltérabilité | rien (petit, à faire en premier) |
| **PAYS-2** | Profil pays unique : pays et subdivision ISO obligatoires, un seul résolveur, défauts alignés, étape bloquante dans le cheminement du site. Correction des données des 4 sites (TVQ 10 %, provinces) et du pays d'esgboost, **sur décision du propriétaire**, sur demo d'abord. | Défauts divergents, taxes fausses | PAYS-1 |
| **PAYS-3** | Paquets de règles datés : Canada d'abord (validé par un comptable), puis France, puis États-Unis. Taux en millionièmes ; mode TTC ouvert au Québec. | Taux non datés, TTC au Québec | PAYS-2 et un comptable par pays |
| **PAYS-4** (l'annulation par écriture nouvelle, sans remise à zéro, est livrée en PAYS-1b le 12/11/2026) | Documents : identité et identifiants du vendeur selon le pays sur le ticket serveur, le courriel et une **facture web PDF à numérotation continue** ; avoir web numéroté ; détail par taux ; annulation en caisse par écriture inverse au lieu de la remise à 0. | Mentions obligatoires, facture web | PAYS-2 et PAYS-3 |
| **PAYS-5** | Taxes sur les achats : par code, avec « récupérable » oui ou non, sur les notes de frais, les commandes et les frais de port ; branchement de l'extraction IA existante ; défaut du lecteur IA selon le pays. Calcul des CTI/RTI et de la TVA déductible. | Montant à déclarer impossible | PAYS-3 |
| **PAYS-6** | Exports : écritures des ventes web (brancher `JournalEntryBuilder`), FEC pour la France, QuickBooks et Sage selon le profil, plan comptable général par défaut pour la France. | Pas de comptabilité hors caisse | PAYS-4 et PAYS-5 |
| **PAYS-7** | Échéances : calendrier par paquet de règles, rappels par les notifications de la gestion, montant préparé (collectées moins récupérables). | Retards de déclaration | PAYS-5 et PAYS-6 |
| **PAYS-8** | États-Unis : Stripe Tax recommandé (comté, ville), suivi des seuils d'assujettissement par État, certificats d'exemption. | Sales tax fausse ou non déclarée | PAYS-3 |

Hors de nos lots, ce qui revient au propriétaire (§ 7.4) : NF525 et la facturation électronique en France.

### 7.3 Ce qui exige un comptable (par pays)
- les taux et les règles de chaque paquet : assiette, livraison, exemptions, arrondi par ligne ou par taux ;
- les mentions obligatoires par document (ticket, facture, avoir) et les seuils ;
- le plan de comptes par défaut et les comptes de taxe ;
- le calendrier et le calcul des déclarations ;
- le traitement des cartes-cadeaux (bon à usage unique ou à usages multiples, en France) ;
- la durée de conservation et le format d'archivage attendus.

### 7.4 Ce qui exige le propriétaire
- **NF525** :
  - choisir un organisme certificateur agréé ;
  - fixer la portée (caisse en ligne et hors ligne) ;
  - décider du calendrier.

  Le dossier technique part du tableau du § 3.5 et du `docs/conception-a3-nf525.md` de posflutter.
- **Facturation électronique en France** : choisir une plateforme agréée. Pour rappel, à confirmer : obligation de réception
  pour toutes les entreprises depuis le 1er septembre 2026, et d'émission pour les PME et microentreprises au
  1er septembre 2027.
- **Comptables** : trouver un comptable par pays, comme le prévoit la décision.
- **Données des sites** : décider de corriger les 4 sites à TVQ 10 % et de renseigner le pays d'esgboost (adresse
  française, devise CAD).
- **États-Unis** : choisir Stripe Tax ou la table.
- **Plateforme** : décider si le lot PAYS est un module payant.

### 7.5 Points de non-régression
- **Caisse en ligne et hors ligne** : toute règle nouvelle va dans `TaxEngine` **et** dans `PosOfflineCalculator`. Il faut
  compléter `config/pos/offline-cases.json`. Si le pack hors ligne change, la version du pack monte, et une tablette restée
  sur l'ancien pack doit toujours pouvoir synchroniser.
- **Ticket** : le bloc JSON ne reçoit que des **ajouts** (l'application de caisse l'imprime) ; la numérotation par poste
  est inchangée.
- **Next et applications mobiles** : `POST /api/cart/quote` et `create-intent` doivent donner les mêmes montants pour un
  site sans profil nouveau. Les champs `pricing` de `ProductCommerceDto` restent inchangés.
- **Commandes passées** : les commandes antérieures au gel des taux restent lisibles ; leur Z, leurs exports et leurs
  retours ne changent pas.
- **Exports F14** : colonnes inchangées, ajouts en fin seulement.
- **Gestion** : les statistiques restent hors taxes ; les avertissements de taxes restent les mêmes.
- **Méthode de mise en ligne** : photo des réponses de demo avant et après chaque lot (devis, ticket, Z, exports, gestion),
  tests de `TaxEngine` et de la caisse hors ligne, 0 réponse 5xx.
