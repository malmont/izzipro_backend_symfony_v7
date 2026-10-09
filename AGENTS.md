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
| Boutique (réglages de la boutique réglable ; produits, commandes, paiement, livraison : aucune en production) | `docs/boutique.md` ; routes dans `docs/endpoints.md` ; demandes du frontend `docs/boutique-reglable-backend-demandes.md` ; côté frontend `src/components/Boutique/README.md` |
| Contrats partagés avec le frontend (schéma, catalogue IA, jeu d'essai, libellés de l'éditeur) | `config/landingpage/` (synchronisés, ne pas modifier à la main) |
| Côté frontend | `AGENTS.md` et `docs/architecture.md` du dépôt `Izzipro_next` |

## Règles non négociables

- Répondre en français.
- **Le code est monté en direct**, et la production tourne en **`APP_ENV=prod`, `APP_DEBUG=0`** depuis le 09/10/2026 : le corps
  d'une méthode PHP modifiée est pris aussitôt (OPcache revalide les fichiers), mais **une route, un service, une entité,
  un gabarit Twig ou un fichier de `config/` ne le sont qu'après**
  `docker exec -u www-data -w /var/www symfony_app_v2 php bin/console cache:clear` (sans coupure). Tester avant, prévoir
  le retour arrière. Journal : `var/log/prod.log` (niveau info). Les tests gardent `APP_ENV=test` et le débogage.
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
