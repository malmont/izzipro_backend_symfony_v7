# Audit PAYS : facture électronique en France, attestation de la caisse, écarts et découpage

> 18/11/2026, session « prevention des pertes backend », à la demande de « new easymakemoney » (lot validé par le
> propriétaire le 17/11/2026). Lecture seule : code de `dev` en ca235dff. Aucun code, aucun contact extérieur.
> Ce document **complète** `docs/pays-audit.md` (audit du 12/11/2026 : profil pays, taxes, documents, NF525, comptabilité,
> lots PAYS-1 à PAYS-8). Ce qui y est déjà écrit n'est pas répété : on y renvoie par son numéro de paragraphe.
>
> **Les règles citées aux § 1 et § 2 sont indicatives.** Elles viennent de sources publiques relevées le 18/11/2026,
> listées au § 8, dont beaucoup sont des sites de prestataires. Elles sont à confirmer sur les textes (CGI, BOFiP,
> spécifications de la DGFiP) et par un expert-comptable avant tout lot.

## 0. Synthèse

1. **Rien n'existe pour la facture électronique.** Aucune trace dans le code ni dans la doc de Factur-X, UBL, CII,
   EN 16931, plateforme agréée ou e-reporting (relevé du 18/11/2026). La gestion ne reçoit que des PDF et des photos,
   lus par l'IA (`GestionDocumentService.php:114`).
2. **L'obligation pèse sur chaque commerçant, pas sur Arkanoa.** Chaque site établi en France doit passer par une
   plateforme agréée. Notre logiciel peut s'y brancher, pour aller chercher les factures reçues et lui remettre les
   factures émises et les données de ventes. Il n'a pas à devenir lui-même une plateforme agréée.
3. **La réception est due depuis le 1er septembre 2026.** Cela vaut pour toute entreprise assujettie à la TVA en France,
   Guadeloupe, Martinique et La Réunion comprises. Aujourd'hui, **aucun site français n'est en service** : esgboost a une
   adresse française, mais sa devise est en CAD et il n'a aucune vente (`pays-audit.md` § 1.1). L'écart est donc daté mais
   sans effet immédiat. Il le devient dès le premier commerçant français.
4. **L'émission et l'e-reporting sont dus au 1er septembre 2027 pour les PME et les microentreprises** (dès 2026 pour les
   grandes entreprises et les ETI). Pour un commerçant, cela veut dire :
   - ses factures aux professionnels passent par la plateforme, au format structuré ;
   - ses ventes aux particuliers sont déclarées en totaux par jour et par taux ;
   - ses encaissements de prestations de services sont déclarés.
5. **La caisse a déjà la matière de l'e-reporting.** Les rapports X et Z portent les taxes par taux, avec base et montant
   (`docs/caisse.md`, PAYS-1). Les taxes sont figées à la vente (PAYS-1), l'annulation se fait sans remise à zéro
   (PAYS-1b), et le Québec est calculé au millionième (PAYS-1c). Il manque l'agrégat de tous les canaux par jour et par
   SIREN, et sa transmission.
6. **Le web n'émet toujours pas de facture.** La référence d'une commande reste `REF#` suivi de `uniqid()`
   (`OrderCreationService.php:23`). Il n'y a ni numérotation continue, ni PDF, ni identification du client
   professionnel : aucun champ SIREN ni n° de TVA du client, seul `Adress.company` existe. C'est le plus gros écart pour
   2027.
7. **Une facture reçue n'est pas gardée comme pièce légale.** Un document confirmé de la gestion est effacé quand ses
   cibles disparaissent : purge DOC-ORPH, `docs/lecture-documents-gestion.md` § 5. Or l'original d'une facture
   électronique reçue doit être conservé tel quel, six ans au titre fiscal et dix ans au titre comptable (durées à
   confirmer).
8. **L'attestation de l'éditeur est de nouveau possible pour la caisse.** L'article 125 de la loi de finances pour 2026
   la rétablit à côté du certificat d'un organisme agréé. Arkanoa, éditeur, peut donc la délivrer lui-même, mais il
   engage sa responsabilité. Les manques techniques sont ceux de `pays-audit.md` § 3.5, moins ce que PAYS-1 et PAYS-1b ont
   réglé (§ 3.3 ci-dessous).

## 1. Facture électronique : ce que la réforme exige (indicatif)

### 1.1 Qui, quand

| Obligation | Grandes entreprises et ETI | PME, TPE, microentreprises |
|---|---|---|
| **Recevoir** les factures de ses fournisseurs français en format électronique | 1er septembre 2026 | 1er septembre 2026 |
| **Émettre** ses factures aux professionnels (B2B domestique) en format électronique | 1er septembre 2026 | 1er septembre 2027 |
| **E-reporting** : ventes aux particuliers (B2C), opérations avec l'étranger, données de paiement des prestations de services | 1er septembre 2026 | 1er septembre 2027 |

- **Territoire** : la France métropolitaine, la Guadeloupe, la Martinique et La Réunion, où la TVA s'applique. En Guyane et
  à Mayotte, pas de facture électronique, mais l'e-reporting de certaines opérations, par exemple les ventes vers un
  territoire où la TVA s'applique.
- **Canada et États-Unis** : aucune obligation équivalente à ce jour pour nos commerçants. Rien à faire dans ces lots.
- **Le canal** : une **plateforme agréée** (anciennement « PDP ») choisie par l'entreprise et inscrite sur la liste de la
  DGFiP. Le portail public n'est plus qu'un annuaire et un concentrateur de données.
- **Sanctions** (à confirmer) : une amende par facture non émise au format électronique et par transmission
  d'e-reporting manquée, avec un plafond annuel.

### 1.2 Ce que doit porter une facture électronique

- **Format** : UBL, CII ou un format mixte. Le Factur-X, un PDF qui porte un XML CII, est l'option la plus simple pour
  un PDF déjà lisible par un humain. Profil EN 16931 au moins, et le profil français (« EXTENDED-CTC-FR ») selon la norme
  AFNOR XP Z12-012.
- **Mentions habituelles** : identité et adresse du vendeur et de l'acheteur, SIREN et SIRET, n° de TVA
  intracommunautaire, numéro unique et continu, date, désignation, quantités, prix hors taxes, TVA par taux, total hors
  taxes, total TVA, total TTC, conditions de paiement, pénalités de retard, indemnité forfaitaire de recouvrement.
- **Quatre mentions nouvelles** :
  - le SIREN du client ;
  - l'adresse de livraison si elle diffère ;
  - la nature de l'opération : biens, services ou mixte ;
  - l'option pour le paiement de la TVA d'après les débits, s'il y a lieu.
- **Statuts du cycle de vie** : la plateforme fait suivre des statuts : déposée, rejetée, refusée, approuvée,
  encaissée. Le statut « encaissée » est obligatoire pour les prestations de services : c'est lui qui alimente les
  données de paiement.
- **Annuaire** : avant d'émettre, la plateforme vérifie dans l'annuaire où le client reçoit ses factures.

### 1.3 E-reporting des ventes aux particuliers

- **Données** : totaux par jour, par SIREN et par taux de TVA (base hors taxes et TVA), sans aucune donnée sur le client.
  S'y ajoutent les encaissements, par jour et par taux, pour les prestations de services.
- **Cadence** : selon le régime de TVA de l'entreprise, à confirmer :
  - réel normal mensuel : trois fois par mois, par décade ;
  - réel normal trimestriel ou régime simplifié : une fois par mois ;
  - franchise en base : une fois tous les deux mois.
- **Pour nous** : les ventes de la caisse et les commandes web aux particuliers entrent dans l'agrégat. Il en va de même
  des locations, réservations et abonnements, qui sont des prestations de services et ajoutent des données de paiement.
  Les cartes-cadeaux restent à trancher par le comptable (`pays-audit.md` § 2.5).

## 2. Caisse : l'attestation de l'éditeur (loi de finances pour 2026, art. 125)

- **Ce qui change** : la loi de finances pour 2025 ne gardait que le certificat d'un organisme agréé. La loi de finances
  pour 2026 (art. 125) rétablit l'**attestation individuelle de l'éditeur** comme preuve possible : le commerçant doit
  détenir l'un des deux documents. Les sources donnent une entrée en vigueur au lendemain de la publication de la loi,
  vers le 21 février 2026, et un modèle d'attestation publié par l'administration (BOI-LETTRE-000242, mars 2026, à
  lire). Ces deux points sont à confirmer.
- **Sanction** (à confirmer) : 7 500 € par logiciel ou système de caisse, si le commerçant n'a ni certificat ni
  attestation (CGI, art. 1770 duodecies).
- **Ce que l'attestation affirme** : le logiciel respecte les quatre conditions (inaltérabilité, sécurisation,
  conservation, archivage) pour une version donnée. L'éditeur la remet au commerçant.
- **Pour nous** : c'est la voie la plus rapide pour un premier commerçant français en caisse. Elle n'est honnête que si
  les manques du § 3.3 sont comblés. C'est une décision du propriétaire, qui signe et engage Arkanoa.

## 3. Ce qui existe déjà (état au 18/11/2026)

### 3.1 Profil pays, taxes, plan de comptes, FEC

| Sujet | État | Référence |
|---|---|---|
| Profil pays | **Absent** : pays en texte libre et plusieurs normaliseurs ; lot PAYS-2 non commencé | `pays-audit.md` § 1.2, § 7.2 |
| Taxes figées à la vente, taxes utilisées protégées et datées | **Fait** (PAYS-1, 12/11/2026) | `docs/caisse.md` « Taxes gelées à la vente » |
| Annulation sans remise à zéro | **Fait** (PAYS-1b) | `docs/caisse.md` |
| Prix TTC au Québec, calcul au millionième, règle hors ligne v5 | **Fait** (PAYS-1c) | `docs/caisse.md` |
| Taxes vérifiées par le commerçant (ligne de la liste d'ouverture) | **Fait** (AG-3d) | `docs/ecrans-et-contextes.md` |
| Régimes de taxes datés par pays (paquets de règles) | **Absent** (PAYS-3) | `pays-audit.md` § 7.1 |
| Plan de comptes | Québécois par défaut ; compte de taxe choisi d'après le libellé | `pays-audit.md` § 4.1 |
| Export FEC | **Absent** : aucune trace de `JournalCode` ni `EcritureNum` dans `src/` | `pays-audit.md` § 4.2 |
| Écritures des ventes web | **Absentes** : `JournalEntryBuilder` prévoit une source « web », branchée nulle part | `pays-audit.md` § 4.1 |

### 3.2 Mentions des factures et des reçus

| Document | Présent | Manque pour la France |
|---|---|---|
| Ticket de caisse (bloc JSON) | Magasin, `taxIds` selon le pays (SIRET et TVA en France), lignes, taxes avec base et taux, paiements, duplicata | Rien côté données pour un ticket. Le ticket n'est pas une facture : un client professionnel qui en demande une relève du web ou d'une facture de caisse (§ 4) |
| Gabarit serveur du ticket (`templates/pos/_receipt_body.html.twig`) | N° TPS, n° TVQ, « N° fiscal / TVA » (l. 11-14), taxes avec taux (l. 42-43) | **SIRET non imprimé**, **base par taux non imprimée**, pas de mention « dont TVA » |
| Facture web | **Aucune** | Tout : numéro continu, identité du vendeur, SIREN du client, TVA par taux, mentions légales, format électronique |
| Avoir web | **Aucun** | Avoir numéroté rattaché à la facture |
| Abonnements | Facture de Stripe Billing | À vérifier : Stripe ne passe pas par une plateforme agréée française |

### 3.3 Numérotation, archivage et inaltérabilité de la caisse

- **Fait** :
  - journal `pos_audit_log` chaîné en SHA-256, avec un déclencheur qui refuse modification et suppression ;
  - reçus numérotés sans trou par poste et par nature ;
  - Z figés et numérotés ;
  - archive annuelle (ZIP, `SHA256SUMS`, contrôle du chaînage, `PosArchiveService`) ;
  - durées légales de conservation (France : 10 ans) ;
  - taux figés (PAYS-1) et annulation par écriture nouvelle (PAYS-1b).
- **Toujours à faire avant une attestation** (`pays-audit.md` § 3.5) :
  - le grand total perpétuel, ainsi que les clôtures mensuelles et annuelles cumulées : aucune trace dans `src/` ;
  - la garde de conservation est **en mode `observe` par défaut** (`Entreprise.php:350-351`) : elle doit passer à
    `block` pour un site français ;
  - une copie de sauvegarde hors du serveur ;
  - une empreinte par ticket et par Z ;
  - la règle du hors-ligne, à décrire.
- **À trancher par le comptable** : le niveau de signature exigé (empreinte chaînée seule, ou signature avec une clé).

### 3.4 Réception des factures fournisseurs (gestion)

- **Ce qui existe** :
  - envoi d'un PDF unique ou de photos (`GestionDocumentService.php:111-130`), lecture par l'IA, aperçu, puis
    confirmation qui crée une commande, une note de frais ou un frais de port ;
  - le document reste en pièce jointe, comme justificatif ;
  - l'empreinte SHA-256 des fichiers est gardée (l. 343).
- **Ce qui manque** :
  - aucun format structuré n'est accepté (XML refusé : 422 `FORMAT`) ;
  - le XML d'un Factur-X est ignoré, alors qu'il donnerait les lignes et la TVA **exactes, sans IA et sans unité IA** ;
  - les taxes extraites ne sont pas enregistrées (`pays-audit.md` § 2.8, PAYS-5) ;
  - l'original n'est pas conservé indépendamment de ses cibles (purge DOC-ORPH) ;
  - aucun statut de cycle de vie (approuvée, refusée, payée) n'est rendu.

## 4. Écarts classés

### 4.1 Obligatoires et datés

| # | Écart | Échéance | Pour qui |
|---|---|---|---|
| O1 | Recevoir les factures électroniques depuis la plateforme du commerçant, garder l'original tel quel, le lire sans IA | **1er septembre 2026 (passée)** ; urgente dès le premier site français | sites établis en France |
| O2 | Conserver l'original d'une facture reçue ou émise pendant la durée légale, sans dépendre de la commande ou de la note | avec O1 | idem |
| O3 | Attestation de l'éditeur, ou certificat, pour la caisse : grand total perpétuel, clôtures cumulées, garde `block`, copie hors serveur | **Exigible dès qu'un commerçant français encaisse** | caisse en France |
| O4 | Émettre les factures aux professionnels : identification du client professionnel (SIREN, n° de TVA, adresse de facturation), numérotation continue par site, PDF Factur-X EN 16931, avoir, statuts | 1er septembre 2027 (PME) | web et caisse, ventes B2B |
| O5 | E-reporting B2C : totaux par jour, par SIREN et par taux, tous canaux ; données de paiement des services ; cadence selon le régime ; transmission et rectification | 1er septembre 2027 (PME) | tous les sites français |
| O6 | Profil pays pour la France : SIREN, SIRET, régime de TVA, cadence de déclaration, taille d'entreprise (GE, ETI ou PME), option sur les débits | prérequis de O1 à O5 | idem |

### 4.2 Utiles

| # | Écart | Pourquoi |
|---|---|---|
| U1 | Taxes des achats tirées du XML reçu : TVA déductible exacte (PAYS-5) | Le montant à déclarer devient calculable sans saisie |
| U2 | Export FEC et plan comptable général (PAYS-6) | Exigé en cas de contrôle quand la comptabilité est tenue chez nous. Sinon, le comptable le produit |
| U3 | Mentions du ticket serveur : SIRET, base par taux, « dont TVA » (PAYS-4) | Mentions françaises du ticket |
| U4 | Facture à la demande en caisse, pour un client professionnel | Un professionnel qui achète au comptoir demande une facture, qui entre dans O4 |
| U5 | Stripe Billing (abonnements) : facture française ou passage par la plateforme | Les abonnements d'un commerçant français sont des prestations de services : facture B2B ou e-reporting |
| U6 | Le résumé de la gestion signale une facture reçue refusée ou rejetée | Suivi du cycle de vie |

### 4.3 Plus tard

- Paquets de règles datés, Canada et États-Unis (PAYS-3, PAYS-8) : aucune obligation électronique.
- Échéances de déclaration et rappels (PAYS-7).
- Opérations avec l'étranger (e-reporting B2B international) : rares chez nos commerçants. À traiter après O5.
- Autres pays d'Europe (ViDA, facture électronique intra-européenne à partir de 2030) : à revoir.

## 5. Découpage proposé

Principes :
- un seul **connecteur de plateforme** derrière une interface, avec un **simulateur** pour les essais (comme le boîtier
  simulé de la prévention des pertes) ;
- aucune plateforme réelle n'est appelée tant que le propriétaire n'a pas choisi et signé (FE-0) ;
- rien ne change pour les sites hors de France ; tout est fermé par défaut, ouvert par le profil pays.

| Lot | Contenu | Couvre | Dépend de |
|---|---|---|---|
| **FE-0** (décisions, sans code) | Choix de la plateforme agréée partenaire par le propriétaire (§ 6), mandat de chaque commerçant, rôle d'Arkanoa (solution branchée à la plateforme), modèle d'attestation de la caisse, expert-comptable référent | tout | rien |
| **PAYS-2 + FR** | Profil pays unique (`pays-audit.md` § 7.1), avec pour la France : SIREN, SIRET, régime de TVA, cadence, taille, option sur les débits. Le pays et le SIREN deviennent une étape du cheminement d'un site français | O6 | rien (déjà prévu) |
| **FE-1** Réception | Connecteur (interface et simulateur). Relève des factures reçues dans un `GestionDocument` « facture électronique », avec l'original rangé à part. Lecture du XML CII, UBL ou Factur-X **sans IA** : lignes, TVA par taux, fournisseur reconnu par son SIREN. Aperçu et confirmation existants réutilisés. Statuts renvoyés à la plateforme : approuvée, refusée. Conservation propre de l'original (10 ans, empreinte, rien ne l'efface) | O1, O2, U6 | FE-0, PAYS-2 |
| **FE-2** Attestation de la caisse | Grand total perpétuel, clôtures mensuelles et annuelles cumulées et figées, garde `block` pour un site français, empreinte par ticket et par Z, copie hors serveur (relève du propriétaire : restic, `tech-dashboard`), dossier technique, attestation par version remise au commerçant (PDF) | O3 | FE-0 (modèle et décision de signer) |
| **FE-3** Facture de vente | Client professionnel (SIREN, TVA, adresse de facturation) sur le compte et à la commande. Numéro de facture continu par site, sous verrou, inaltérable (journal comme celui de la caisse). Facture et avoir en PDF Factur-X EN 16931, avec les quatre mentions nouvelles. Facture à la demande en caisse. Courriel. La commande garde sa référence actuelle : la facture est un document à part | O4, U3, U4 | PAYS-2, PAYS-3 (FR) |
| **FE-4** Émission | Dépôt de la facture et de l'avoir à la plateforme, statuts reçus (rejetée, encaissée), reprise d'un rejet, journal des envois | O4 | FE-1 (connecteur), FE-3 |
| **FE-5** E-reporting | Agrégat par jour, par SIREN et par taux, tous canaux : caisse (X et Z), web B2C, locations, réservations. Données de paiement des services. Cadence lue du profil, dépôt à la plateforme, rectification d'une période, journal. Calcul en lecture sur les ventes figées, sans écriture dans la caisse | O5 | FE-1 (connecteur), PAYS-2 |
| **PAYS-5** Taxes des achats | Branchement du XML (FE-1) et de l'extraction IA existante sur des taxes enregistrées, TVA déductible | U1 | FE-1 |
| **PAYS-6** FEC | Écritures web, FEC, plan comptable général | U2 | FE-3, PAYS-5 |

**Ordre recommandé** :
1. FE-0 et PAYS-2 en premier : sans plateforme choisie ni SIREN, rien ne peut partir.
2. Ensuite FE-1 et FE-2, en parallèle : ce sont les deux obligations déjà exigibles pour un premier commerçant français.
3. Puis FE-3, FE-4 et FE-5, avant l'été 2027 pour garder une marge avant le 1er septembre 2027.

**Taille relative** : FE-3 et FE-5 sont les plus gros (nouvelle numérotation inaltérable, agrégat tous canaux). FE-1 et
FE-4 dépendent surtout de l'interface de la plateforme choisie. FE-2 est moyen, et s'appuie sur le journal de caisse
existant.

**Non-régression** (en plus de `pays-audit.md` § 7.5) :
- aucun site hors de France ne voit de changement : profil absent ou autre pays, alors rien ne change ;
- la caisse en ligne et hors ligne garde ses montants ;
- le ticket JSON ne reçoit que des ajouts ;
- la référence `REF#` des commandes reste lue par les applications.

## 6. Questions au propriétaire

1. **Plateforme agréée** : laquelle ? Critères proposés :
   - une API pour un éditeur qui sert plusieurs entreprises (un SIREN par site) ;
   - un mandat simple pour chaque commerçant ;
   - Factur-X et CII acceptés ;
   - e-reporting B2C compris ;
   - un bac à sable pour les essais ;
   - un prix par site ou par document.
2. **Rôle d'Arkanoa** : branché à une seule plateforme partenaire pour tous les sites, ou le commerçant apporte la sienne ?
   Une plateforme partenaire unique est recommandée pour commencer.
3. **Caisse** : signer l'attestation de l'éditeur (responsabilité d'Arkanoa), ou passer par un certificat (organisme
   agréé, coût et délai) ?
4. **Expert-comptable** : qui valide les mentions, la cadence de l'e-reporting, la conservation, les cartes-cadeaux et
   l'arrondi ?
5. **Offre** : la facture électronique est-elle comprise pour tout site français, ou fait-elle partie d'un module payant
   (les plateformes facturent souvent au document) ?
6. **esgboost** : est-ce un vrai commerçant français (adresse à Moirans, devise CAD) ? Si oui, il est le premier concerné
   par O1.

## 7. Ce que cet audit n'a pas fait

- Aucune base de site n'a été lue (ni demo, ni site client) : les données des sites sont celles du 12/11/2026
  (`pays-audit.md` § 1.1).
- L'application de caisse (posflutter) n'a pas été relue. Son ticket papier est hors de ce dépôt.
- Aucune plateforme n'a été contactée. Aucune interface de plateforme n'a été étudiée : FE-1 et FE-4 seront chiffrés
  après le choix de FE-0.

## 8. Sources (relevées le 18/11/2026, à confirmer sur les textes)

- DGFiP : [Je découvre la facturation électronique](https://www.impots.gouv.fr/professionnel/je-decouvre-la-facturation-electronique),
  [FAQ « J'approfondis la facturation électronique »](https://www.impots.gouv.fr/foire-aux-questions-japprofondis-la-facturation-electronique),
  [norme XP Z12-012](https://www.impots.gouv.fr/factures-norme-afnor).
- Service public : [actualité facturation électronique](https://entreprendre.service-public.gouv.fr/actualites/A17450).
- Calendrier : [compta-online](https://www.compta-online.com/facture-electronique-calendrier-ao8920),
  [ma-facture-electronique.org](https://ma-facture-electronique.org/reforme-2026/calendrier/).
- E-reporting B2C : [ma-facture-electronique.org](https://ma-facture-electronique.org/e-reporting/e-reporting-b2c/),
  [mooncard](https://www.mooncard.co/fr/blog/e-reporting-donnees-frequence-canal).
- Outre-mer : [ma-facture-electronique.org, DROM-COM](https://ma-facture-electronique.org/cas-dusage/drom-com-taaf/),
  [fiducial](https://www.fiducial.fr/facturation-electronique/actualites-conseils/facturation-electronique-avec-en-outre-mer).
- Mentions : [fiducial](https://www.fiducial.fr/facturation-electronique/faq/mentions-obligatoires-facture-electronique).
- Caisse, loi de finances pour 2026 art. 125 : [legifiscal](https://www.legifiscal.fr/actualites-fiscales/4460-loi-finances-2026-retablissement-auto-certification-logiciels-caisse.html),
  [socic](https://www.socic.fr/ressources-comptabilite/articles/loi-de-finances-2026-retour-de-lauto-certification-des-logiciels-de-caisse-attestation-editeur-vs-certificat-tva-controles-et-sanctions),
  [comptanoo](https://www.comptanoo.com/logiciel-de-caisse-certifie-nf525-ce-que-dit-la-loi-en-2026/).
