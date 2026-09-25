# Rocket Auth

Fournisseur d'identité (SSO) du Middleware Rocket : un serveur **OpenID Connect** qui connecte les utilisateurs à [Rocket Mailer](https://github.com/fayouz/rocket-mailer), [Rocket Cloud](https://github.com/fayouz/rocket-cloud), [Rocket Print](https://github.com/fayouz/rocket-print), Rocket Doc Fusion et à toute application compatible. Comptes locaux, annuaire LDAP, groupes, clients OAuth, écran d'autorisation, et liste des applications de la suite pour leur sélecteur.

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform 5, Doctrine ORM 3 (PostgreSQL), StofDoctrineExtensions, LexikJWT, Messenger et Scheduler, LDAP |
| `frontend/` | Nuxt 4, Nuxt UI 4 : connexion, page d'autorisation, déconnexion, applications autorisées, administration |
| `docs/` | Site de documentation (Nuxt UI + Nuxt Content), avec le changelog sur `/changelog` : `cd docs && npm install && npm run dev`, puis http://localhost:3101 |

Le socle commun (comptes, LDAP, SSO, applications, tableau de bord, mises à jour, modes autonome et suite) vient de **[rocket-core](https://github.com/fayouz/rocket-core)** : le bundle Symfony `rocket/core-bundle` (Composer) et le layer Nuxt `@rocket/core` (npm). Pour travailler sur les deux à la fois : `ROCKET_CORE_LAYER=../../rocket-core/nuxt npm run dev` côté front, et un dépôt `path` Composer côté backend.

## Démarrage rapide

```bash
docker compose up -d --build
```

Au premier lancement, http://localhost:3100 affiche la **configuration initiale** : on y crée le compte administrateur. Si l'instance est exposée avant d'être configurée, définissez `SETUP_TOKEN`. L'administrateur peut aussi être créé en ligne de commande : `docker compose exec api php bin/console app:user:create admin@example.org 'un-mot-de-passe-long' --admin`.

Déclarez ensuite les applications dans **Administration → Clients OAuth**.

- Application : http://localhost:3100
- Découverte OpenID Connect : http://localhost:8100/.well-known/openid-configuration
- API + documentation OpenAPI : http://localhost:8100/api/docs

### Démo prête à tester

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, groupe `rocket-admins`, et les briques de la suite (Rocket Mailer, Rocket Cloud, Rocket Print) déjà déclarées comme clients OAuth. Voir [demo/README.md](demo/README.md).

### Développement sans Docker

```bash
# backend (PHP 8.4, PostgreSQL)
cd backend && composer install
php bin/console lexik:jwt:generate-keypair
php bin/console app:oidc:generate-key
php bin/console doctrine:migrations:migrate
echo 'MESSENGER_TRANSPORT_DSN=sync://' >> .env.local   # ou lancer messenger:consume async scheduler_default
php -S 127.0.0.1:8100 -t public
php bin/phpunit

# frontend
cd frontend && npm install && npm run dev -- --port 3100   # NUXT_PUBLIC_API_BASE=http://localhost:8100
```

## Fonctionnalités

### Fournisseur OpenID Connect
| Point d'accès | Rôle |
|---|---|
| `/.well-known/openid-configuration` | Découverte |
| `/oauth/authorize` | Autorisation : renvoie vers la page d'autorisation de l'interface (`FRONTEND_URL`) |
| `/oauth/token` | `authorization_code` (PKCE S256), `refresh_token` (renouvelé à chaque usage), `client_credentials` |
| `/oauth/userinfo` | Revendications de l'utilisateur selon les scopes |
| `/oauth/jwks` | Clé publique de signature (RS256) |
| `/oauth/revoke` | Révocation d'un jeton de rafraîchissement |
| `/oauth/logout` | Déconnexion (*RP-initiated logout*), retour vers une adresse déclarée |

- Scopes `openid`, `profile`, `email`, `groups` et `offline_access`. La revendication `groups` porte les groupes du compte (annuaire, fournisseur externe ou saisis à la main).
- Émetteur `OIDC_ISSUER` ; clé de signature dédiée, générée au démarrage (`app:oidc:generate-key`, `--rotate` pour la remplacer), distincte des sessions Rocket Auth.
- Un code réutilisé ou un jeton de rafraîchissement rejoué révoque tous les jetons de l'utilisateur pour l'application.

### Clients OAuth
Administration → **Clients OAuth** : client ID, confidentiel (secret `ras_…`, affiché une fois, seul son hash est stocké) ou public (PKCE), adresses de retour et de déconnexion, scopes et flux autorisés, application de confiance (pas d'écran d'autorisation), adresse et icône pour le sélecteur de la suite. API : `/api/oauth/clients`.

### Utilisateurs
- **Page d'autorisation** et **Applications autorisées** : chaque utilisateur voit ce que demande une application, l'autorise ou non, et retire un accès (`/api/consents`).
- Comptes **locaux**, **LDAP** (synchronisation, groupes, rôle admin par groupe) et **fournisseurs OpenID Connect externes** (Entra ID, Keycloak…).

### Suite Rocket
Une brique Rocket passe en mode suite avec `ROCKET_AUTH_URL` (l'émetteur), `ROCKET_AUTH_CLIENT_ID` (défaut `rocket-<id>`), `ROCKET_AUTH_CLIENT_SECRET` et `ROCKET_AUTH_ADMIN_GROUP` (défaut `rocket-admins`). Elle affiche les applications publiées par `GET /api/suite/apps` : les clients actifs qui ont une adresse. Voir `docs/content/5.administration/3.suite.md`.

### Socle commun Rocket (rocket-core)
- **Applications externes** : jeton `raa_…` (seul son hash est stocké) et impersonation par `X-Impersonate-User`, jamais avec le rôle administrateur.
- **Tableau de bord** : connexions aux applications et à Rocket Auth, refus, sessions actives, clients OAuth, état des services (base, tâches de fond, LDAP, SSO, stockage).
- **Version et mises à jour** : Docker (Watchtower, profil `updater`), serveur sans Docker (`deploy/update.sh`) ou manuelle.
- **Traçabilité** : toutes les entités sont Timestampable et Blameable ; connexions, autorisations et refus sont enregistrés.

## CI/CD

`.github/workflows/ci.yml` :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build du front et de la documentation ; la démo complète est lancée et vérifiée ;
- sur `main`, `develop` et les tags `v*` : images `ghcr.io/fayouz/rocket-auth-api` et `ghcr.io/fayouz/rocket-auth-front`.

Le worker utilise l'image API avec `php bin/console messenger:consume async scheduler_default`.

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`) ; `develop` : intégration.
- `feature/*` : pull request vers `develop`, qui complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md), publiée sur `/changelog` dans la documentation.
- `release/*` et `hotfix/*` vers `main` : `[Non publié]` devient `[X.Y.Z] - date`, puis tag `vX.Y.Z`.
