# app_v2 — carte du backend pour les agents

Backend Symfony 6.4 multi-tenant (une base par client), API du frontend Next.js `Izzipro_next` (autre dépôt).
Lire la fiche utile ci-dessous, puis aller droit aux fichiers qu'elle cite : ne pas parcourir tout le code.

## Où chercher

| Question | Document |
|---|---|
| Tenants, sécurité, couches du code, Messenger, stockage, variables, déploiement, tests | `docs/architecture.md` |
| Une route : méthode, rôle exigé, contrôleur | `docs/endpoints.md` (généré, voir son en-tête) |
| Landing Page : réglages, validation, assistant IA, synchronisation | `docs/landingpage.md`, puis `config/landingpage/README.md` |
| Mémoires Vivantes | `docs/memoires-vivantes.md` |
| Boussole ESG | `docs/boussole-esg.md` ; code `src/ESG/` |
| Caisse (POS : postes de caisse, sessions, vente `create-multi-payment`, remboursements, droits du caissier) | `docs/caisse.md` |
| Mise en production de la caisse (paiements réels, mode Stripe par site, réconciliation planifiée, surveillance, liste de contrôle d'ouverture) | `docs/mise-en-production-caisse.md` |
| Boutique (réglages de la boutique réglable ; produits, commandes, paiement, livraison : aucune en production) | `docs/boutique.md` ; routes dans `docs/endpoints.md` ; demandes du frontend `docs/boutique-reglable-backend-demandes.md` ; côté frontend `src/components/Boutique/README.md` |
| Aide intégrée pour les administrateurs (pages `docs/aide/<clé>.md`, API `/api/site/help`, page `/admin/aide`) | `docs/console-pilotage.md`, « aide intégrée » ; pages dans `docs/aide/` |
| Module Assistance (tickets, plaintes, aide IA au répondeur, chat : cadrage, responsabilités backend / Next / MAUI / caisse) | `docs/assistance.md` |
| Incidents de la plateforme et signalements de bogues par les applications et leurs utilisateurs (cadrage, deux circuits, lots, responsabilités) | `docs/incidents-et-signalements.md` |
| Application de gestion (achats : collections, commandes fournisseurs, frais de port, notes de frais, tableaux de bord, statistiques ; mise au niveau cents / devise / langue / fuseau, lots G0–G5, contrat avec l'application) | `docs/gestion-mise-a-niveau.md` ; ligne « Gestion (achats) » de `docs/architecture.md` |
| Périodes de gestion (collections automatiques par calendrier de site, état par date, résultat de période, reprise d'historique) | `docs/periodes-gestion.md` |
| Lignes d'achat de la gestion (commande_line : réassorts, coût moyen à la date de la vente, rattacher idempotent, vérification `app:gestion:lignes:verifier`) | `docs/lignes-achat-gestion.md` |
| Volet publicité de la gestion (campagnes, devise de la régie, dépense de période, rentabilité perçue avant / après et l'an dernier) | `docs/publicite-gestion.md` |
| Budgets par poste et voyages d'achat de la gestion (prévu / réel par période, voyage avec budget journalier, rattachement des notes et commandes) | `docs/budgets-gestion.md` |
| Vignettes d'accueil de la gestion (premiers pas, alerte de stock bas, écart par utilisateur) | `docs/vignettes-gestion.md` |
| Notifications sur téléphone de la gestion (appareils, préférences, une par jour, stock bas à 10 h, facture en double ; Firebase à brancher) | `docs/notifications-gestion.md` |
| Lecture de documents par IA pour la gestion (factures fournisseurs, notes de frais, transport : envoi asynchrone, extraction, confirmation, justificatifs, worker `gestion_ai`) | `docs/lecture-documents-gestion.md` |
| Contenu d'exemple des sites (site de référence `gmasuite`, `isDemo`, copie, suppression) | `docs/contenu-exemple.md` |
| Console de pilotage des fonctionnalités (inventaire des réglages par module, registre, droits d'usage, onglets admin / super admin, lots) | `docs/console-pilotage.md` |
| Contrats partagés avec le frontend (schéma, catalogue IA, jeu d'essai, libellés de l'éditeur) | `config/landingpage/` (synchronisés, ne pas modifier à la main) |
| Côté frontend | `AGENTS.md` et `docs/architecture.md` du dépôt `Izzipro_next` |

## Règles non négociables

- Répondre en français.
- **Le code est monté en direct : toute modification est aussitôt en production** (`APP_ENV=dev`). Tester avant, prévoir le retour arrière.
  Débogage coupé depuis le 17/10/2026 (`APP_DEBUG=0`) : le corps d'une méthode PHP est relu aussitôt, mais un changement de
  `config/`, de route, de service (constructeur, nouvelle classe injectée) ou d'entité exige
  `docker exec -w /var/www symfony_app_v2 php bin/console cache:clear` (les tests, eux, gardent le débogage : forcé dans
  `phpunit.xml.dist`, car la variable `APP_DEBUG=0` du conteneur passe avant `.env.test`).
- Essais sur des données : uniquement sur le site de test `demo` (`demo.arkanoa-media.com`, base `db_demo`) ; jamais sur un site client.
- Un site (tenant) n'existe que par sa ligne dans la table `tenants` de la base maître : rien en dur (procédure dans `docs/architecture.md`).
- **Ne pas committer sans l'accord explicite de l'utilisateur** (même si un prompt collé le demande) ; **ne jamais pousser** sans accord.
- Couches : **Repository > Service > UseCase > Controller > DTO** (+ EasyAdmin si utile) ; s'appuyer sur l'existant
  (conventions et exemples : `docs/architecture.md`, « Organisation du code et conventions »).
- Multi-tenant : toujours `App\Services\TenantEntityManagerProvider` (`getEntityManager()->getRepository(…)`), jamais
  l'EntityManager par défaut ; dépôts qui **étendent `EntityRepository`** (pas `ServiceEntityRepository`) ; CRUD
  EasyAdmin qui **étendent `BaseTenantCrudController`** ; workers et commandes : `switchTenant($dbname, $code)`.
- Toute route d'écriture exige un rôle (`access_control`, voter ou `#[IsGranted]`) : garde-fou `tests/Functional/Security/AnonymousWriteAccessTest.php`.
- Schéma : migration Doctrine idempotente **et** script `scripts/migrate_all_v2_<sujet>.sh` (partir d'un existant)
  appliqué aussitôt à toutes les bases, `gmasuite` et le modèle des tests compris.
- Aucun secret dans le code, la doc ou les journaux (ni valeur de `.env`, ni jeton, ni URL avec identifiants).
- Tests : `docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 php vendor/bin/phpunit tests/Functional`.
- Après un changement de handler Messenger : `messenger:stop-workers` (vérifier d'abord qu'aucune génération n'est en cours).
- **Tenir la doc à jour** : à chaque changement structurel, mettre à jour la fiche concernée ; après tout ajout ou
  changement de route, régénérer `docs/endpoints.md` (commande en tête du fichier).
