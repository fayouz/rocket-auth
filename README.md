# Rocket Auth

Envoi d'emails en texte enrichi, templates d'email visuels, et composeur embarquable dans des applications tierces.

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform 5, Doctrine ORM 3 (PostgreSQL), StofDoctrineExtensions, LexikJWT, Messenger, Mailer, LDAP |
| `frontend/` | Nuxt 4, Nuxt UI 4, CKEditor 5 (composeur), GrapesJS + preset newsletter (templates) |
| `integrations/` | Clients pour les applications appelantes : layer Nuxt (`integrations/nuxt`) et bundle Symfony (`integrations/symfony`), publiés dans des dépôts miroirs par `.github/workflows/split.yml` |
| `docs/` | Site de documentation (Nuxt UI + Nuxt Content), avec le changelog sur `/changelog` : `cd docs && npm install && npm run dev`, puis http://localhost:3001 |

## Démarrage rapide

```bash
docker compose up -d --build
```

Au premier lancement, http://localhost:3000 affiche la **configuration initiale** : on y crée le compte administrateur (email et mot de passe). Si l'instance est exposée avant d'être configurée, définissez `SETUP_TOKEN` : la page le demandera. L'administrateur peut aussi être créé en ligne de commande : `docker compose exec api php bin/console app:user:create admin@example.org 'un-mot-de-passe-long' --admin`.

- Application : http://localhost:3000
- API + documentation OpenAPI : http://localhost:8000/api/docs
- Emails reçus (Mailpit) : http://localhost:8025

### Démo prête à tester

`docker compose -f compose.yaml -f compose.demo.yaml up -d --build` lance une démo complète : comptes locaux et LDAP, templates, et une application tierce qui embarque le composeur. Voir [demo/README.md](demo/README.md).

### Développement sans Docker

```bash
# backend (PHP 8.4, PostgreSQL)
cd backend && composer install
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
echo 'MESSENGER_TRANSPORT_DSN=sync://' >> .env.local   # ou lancer messenger:consume async
php -S 127.0.0.1:8000 -t public
php bin/phpunit

# frontend
cd frontend && npm install && npm run dev            # NUXT_PUBLIC_API_BASE=http://localhost:8000
```

## Fonctionnalités

### Tableau de bord et suivi des envois
- **Tableau de bord** (page d'accueil) : envois et délivrabilité sur 30 jours, file d'envoi, intégrations, activité récente, état des services (base, file, SMTP, LDAP, stockage). Un utilisateur y voit ses propres chiffres ; un administrateur, toute la plateforme. API : `GET /api/dashboard`.
- **Mes envois** et **Tous les envois** (admin) : recherche dans l'objet et les destinataires, filtres par statut, application, expéditeur et période, pagination. Les mêmes filtres existent dans l'API (`GET /api/emails?q=…&status=…`).

### Utilisateurs, LDAP et authentification unique
- Comptes **locaux** (mot de passe haché), **LDAP** (authentification par bind sur l'annuaire) ou **SSO** : connexion via un fournisseur **OpenID Connect** comme [Rocket Auth](https://github.com/fayouz/rocket-auth) (Administration → Serveurs d'authentification). Voir `docs/content/5.administration/8.sso.md`.
- Synchronisation : `php bin/console app:ldap:sync [--dry-run]` (à planifier en cron) ou bouton « Synchroniser LDAP » (admin).
  Elle crée et met à jour les comptes et désactive ceux qui ont disparu de l'annuaire. Elle ne prend jamais le contrôle d'un compte local portant le même email.
- `LDAP_ADMIN_GROUP_DN` : les membres de ce groupe (attribut `memberOf`) reçoivent `ROLE_ADMIN`. Vide : les admins sont gérés dans l'application.
- Configuration dans **Administration → Annuaire LDAP** (stockée en base, mot de passe chiffré, bouton **Tester**). Les variables `LDAP_ENABLED`, `LDAP_URL`, `LDAP_START_TLS`, `LDAP_BASE_DN`, `LDAP_SEARCH_DN`, `LDAP_SEARCH_PASSWORD`, `LDAP_USER_FILTER`, `LDAP_ADMIN_GROUP_DN` et `LDAP_ATTRIBUTE_*` en sont la configuration par défaut.

### Applications externes et impersonation
Un administrateur crée une application. Son jeton secret (`raa_…`) n'est affiché qu'une seule fois, et seul son hash SHA-256 est stocké.

| En-têtes | Effet |
|---|---|
| `Authorization: Bearer raa_…` | L'application s'identifie (accès limité à `GET /api/me`). |
| `+ X-Impersonate-User: jean@exemple.org` | L'application agit **en tant que** cet utilisateur (si « impersonation » est autorisée). Elle n'obtient **jamais** `ROLE_ADMIN`, même en impersonnant un admin. |

Chaque email envoyé garde l'utilisateur **et** l'application d'origine. Désactiver une application ou régénérer son jeton coupe l'accès immédiatement.

### Composeur embarqué (widget)
1. **Côté serveur de l'application tierce** (le secret ne doit jamais aller dans le navigateur) :
   ```bash
   curl -X POST https://mailer.exemple.com/api/embed/token \
     -H "Authorization: Bearer raa_…" -H "X-Impersonate-User: jean@exemple.org"
   # → { "token": "<jwt 15 min>", "applicationId": "…", "expiresAt": "…" }
   ```
2. **Côté navigateur** :
   ```html
   <script src="https://mailer.exemple.com/embed.js"></script>
   <div id="mailer"></div>
   <script>
     RocketMailer.mount('#mailer', {
       baseUrl: 'https://mailer.exemple.com',
       applicationId: '<uuid de l’application>',
       getToken: () => fetch('/mon-backend/rocket-auth-token').then(r => r.json()).then(d => d.token),
       draft: { to: ['client@exemple.com'], subject: 'Votre devis' }, // optionnel
       onSent: (email) => console.log('envoyé', email),               // optionnel
     })
   </script>
   ```

Ou, avec le **web component** défini par le même script :

```html
<rocket-auth-composer application-id="<uuid>" token-url="/mon-backend/rocket-auth-token"></rocket-auth-composer>
```

Pour une application Nuxt ou Symfony, utilisez plutôt les clients de `integrations/` : ils fournissent le composant, l'endpoint de jeton et l'envoi côté serveur. Le bouton **Intégrer** du composeur génère le code pour une application donnée.

Sécurité du composeur embarqué :
- **Origines autorisées :** la page `/embed/compose` n'est affichable que depuis les origines déclarées sur l'application (`Content-Security-Policy: frame-ancestors`). Toutes les autres pages envoient `frame-ancestors 'none'`.
- **Transmission du jeton :** le jeton passe par `postMessage` ; l'iframe n'accepte que les messages venant de `window.parent` et d'une origine autorisée. Il reste en mémoire (pas de cookie, pas d'URL) et il est renouvelé automatiquement via `getToken` quand il expire.
- **Jeton d'embed :** c'est un JWT à scope `embed`, envoyé avec `Authorization: Embed <jwt>`. Il ne peut que lister et lire les templates, envoyer un email et lire ses propres envois. Il est refusé comme session utilisateur (`Bearer`) et révoqué dès que l'application est désactivée.

### Adresse d'expédition (« De »)
- Le composeur a une liste **De**. Elle propose les adresses d'expédition des **Réglages** (l'adresse par défaut est présélectionnée) et l'adresse de l'utilisateur, sauf si les Réglages l'interdisent.
- À l'installation, `MAILER_DEFAULT_FROM="Nom <adresse>"` crée l'adresse par défaut. On la gère ensuite dans les Réglages.
- Une application peut imposer l'adresse à la volée, avec `setDraft({ from })` ou le champ `from` de l'API, dans la limite de ses **adresses d'expédition autorisées** (`contact@…` ou `*@domaine`). Toute autre adresse est refusée. Les réponses reviennent à l'utilisateur (`Reply-To`).

### Version et mises à jour
- Version affichée en bas du menu (`git describe --tags`, inscrite dans les images par la CI). Administration → **Mises à jour** la compare aux versions publiées sur GitHub (`UPDATE_REPOSITORY`).
- Bouton **Mettre à jour**, avec trois méthodes au choix :
  - **Docker** : service optionnel `updater` (Watchtower), `UPDATER_TOKEN=… docker compose --profile updater up -d`, avec les images ghcr.io (`API_IMAGE`, `FRONT_IMAGE`) ;
  - **sans Docker** : cron `php bin/console app:update:run`, qui lance `deploy/update.sh` ;
  - **manuelle**.

### Boîtes d'envoi
- Administration → **Boîtes d'envoi** : de vrais comptes email (SMTP, ou fournisseur par DSN : Brevo, SES, Mailjet, SendGrid, Postmark, Mailgun), avec copie de chaque email dans leur dossier « Envoyés » par IMAP.
- Rattachées à des applications, elles apparaissent dans la liste « De » de leur composeur (ou pour tous les utilisateurs). API : `mailbox` dans `POST /api/emails` ; widget : `setDraft({ mailbox })`.
- Mots de passe chiffrés en base avec `MAILBOX_ENCRYPTION_KEY` (vide : dérivée de `APP_SECRET`).

### Pièces jointes
- Dans le composeur (application et widget), avec le bouton **Joindre des fichiers** ou par glisser-déposer : 10 fichiers, 10 Mo par fichier et 25 Mo par email par défaut. Les exécutables et scripts sont refusés.
- Une application peut joindre un document qu'elle génère, par exemple un devis PDF. Son backend le téléverse au nom de l'utilisateur, puis la page le passe au widget avec `setDraft({ attachments: [id] })`.
- Stockage sur disque dans `ATTACHMENTS_DIR`, un volume partagé entre l'API et le worker. Les fichiers jamais envoyés sont purgés par `app:attachments:purge`.

### Templates d'email
- Éditeur visuel GrapesJS (preset newsletter). On stocke le projet GrapesJS (réédition) et le HTML email avec CSS inliné (import).
- Import dans le composeur via « Importer un template » : le contenu arrive dans CKEditor, qui conserve le balisage d'email grâce à General HTML Support.
- Templates privés ou partagés ; seul le propriétaire (ou un admin) les modifie.
- **Variables** `{{ client.prenom }}` : insérées avec le bouton **{x}** de l'éditeur, avec un libellé et une valeur par défaut. Les valeurs viennent du composeur, de l'application qui l'embarque (`setDraft({ template, variables })`) ou de l'API (`POST /api/emails` avec `template` et `variables`).
- **Layouts** (Administration → Layouts d'email) : enveloppe HTML commune avec l'emplacement `{{ content }}`, choisie par template ; le composeur et l'API utilisent le HTML final (`renderedHtml`).
- **Versionnés** (Gedmo Loggable) : `GET /api/email_templates/{id}/versions` et `POST …/versions/{n}/restore`.

### Traçabilité
Toutes les entités sont **Timestampable** et **Blameable** (`createdAt`, `updatedAt`, `createdBy`, `updatedBy`) via StofDoctrineExtensionsBundle. Seuls les templates sont versionnés.

## CI/CD

`.github/workflows/ci.yml` :
- à chaque push et pull request : lint du container, validation du schéma Doctrine, PHPUnit, puis ESLint, typecheck et build Nuxt ;
- sur `main`, `develop` et les tags `v*` : build et push des images sur **ghcr.io** :
  - `ghcr.io/fayouz/rocket-auth-api`
  - `ghcr.io/fayouz/rocket-auth-front`

  Les tags d'image suivent le nom de branche, le semver, le sha court, et `latest` pour `main`.

Le worker utilise l'image API avec `php bin/console messenger:consume async scheduler_default` (envois, et tâches planifiées comme les vérifications de santé).

## Gitflow

- `main` : production (images `latest` et tags `vX.Y.Z`)
- `develop` : intégration (images `develop`)
- `feature/*` : une fonctionnalité, en pull request vers `develop`. Chaque pull request complète la section `[Non publié]` de [CHANGELOG.md](CHANGELOG.md) (format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)), publiée sur la page `/changelog` de la documentation.
- `release/*` et `hotfix/*` : préparation de version et correctifs vers `main`. À la release, `[Non publié]` devient `[X.Y.Z] - date`, puis on tague `vX.Y.Z`.
