# Rocket Auth

Brique du Middleware Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici.

## Repères
- `app_id` `auth`, jetons d'application `raa_…`, ports front 3100 · api 8100 · docs 3101.
- Domaine : fournisseur OpenID Connect de la suite : `src/` (OAuth clients, codes, refresh tokens, consentements, `OidcProviderController`, `SuiteAppsController`, back-channel logout vers les briques, client credentials avec `audience`).
- Stack : Symfony 8.1 + API Platform + Doctrine/PostgreSQL + LexikJWT + Messenger/Scheduler (`backend/`), Nuxt 4 + Nuxt UI 4 qui étend le layer (`frontend/`), Nuxt Content (`docs/`), Docker Compose (`compose.yaml` + `compose.demo.yaml`).
- Points d'extension du socle (interfaces) : `DashboardSectionInterface`, `ServiceProbeInterface`, `DemoSeederInterface`, `RecurringTaskProviderInterface`, `EmbedEndpointsInterface` ; côté front `rocket.extensions` dans `app.config.ts`.
- Mode suite : `ROCKET_AUTH_URL` non vide (voir le README de rocket-core).
- C'est le fournisseur d'identité : il n'a pas de `ROCKET_AUTH_*` (il ne se connecte pas à lui-même).
- `DEMO_OAUTH_CLIENTS` : `clientId|Nom|secret|redirect|post-logout|homeUrl|icône|backchannel` (secrets ≥ 24 caractères).

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```
CI : `.github/workflows/ci.yml` appelle les workflows réutilisables de rocket-core (`@vX.Y.Z`) ; scénarios de démo dans `.github/demo-scenarios.sh`.

## Pièges connus
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json` pour obtenir un tableau (scripts, curl, jq).
- Local sans `ext-ldap` : `composer install --ignore-platform-req=ext-ldap`. Composer installe depuis les sources git : `vendor/*/*/.git` pèse plusieurs Go, à supprimer si le disque manque.
- Base de test partagée entre dépôts en local (`app_test`) : la recréer et migrer si des colonnes manquent.
- rocket-core suit semver (`^0.x`) ; Renovate ouvre les mises à jour (correctifs fusionnés seuls si la CI passe). Nouvelles migrations du socle : idempotentes (`write()` + `return`), jamais `skipIf`.
- Ne pas copier une page du layer pour l'étendre : utiliser `rocket.extensions`.
