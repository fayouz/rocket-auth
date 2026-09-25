# Changelog

Toutes les évolutions notables de Rocket Auth. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté

- **Authentification unique (OpenID Connect)** : nouveau type de serveur d'authentification, pour se connecter avec Rocket Auth ou tout fournisseur OpenID Connect :
  - bouton **Se connecter avec …** sur la page de connexion, pour chaque fournisseur actif ;
  - flux *authorization code* avec PKCE, `state` et `nonce`. L'API vérifie le jeton d'identité (signature RS256 via JWKS, émetteur, audience, expiration) ;
  - comptes créés à la première connexion (source « SSO »), rôle administrateur piloté par la revendication `groups` (optionnel), rattachement des comptes existants seulement pour un fournisseur de confiance ;
  - secret du client chiffré en base, URL interne pour les déploiements Docker, vérification du fournisseur par le worker et sur le tableau de bord ;
  - API : `GET /api/auth/providers`, `POST /api/auth/oidc/callback`, `POST /api/authentication_servers/oidc/test`. Voir la documentation, *Administration → Authentification unique*.

- **Santé des boîtes d'envoi et de l'annuaire LDAP** sur le tableau de bord :
  - le worker vérifie toutes les 5 minutes (Symfony Scheduler) chaque boîte active (connexion et authentification SMTP, IMAP si la copie est activée) et le serveur LDAP (authentification du compte de service, lecture de la base) ;
  - le tableau de bord affiche l'état de chaque boîte, l'erreur éventuelle et depuis quand un service est en échec ; bouton **Vérifier** pour relancer tout de suite ;
  - commande `app:health:check` (code de sortie 1 en cas d'échec, pour une supervision) ; API `POST /api/health/check`.

- **Version et mises à jour** :
  - la version installée s'affiche en bas du menu ; les administrateurs voient un badge **Nouveau** quand une version plus récente est publiée ;
  - page Administration → **Mises à jour** : version installée, dernière version publiée sur GitHub avec ses notes, et bouton **Mettre à jour** ;
  - trois **méthodes de mise à jour**, au choix dans la page :
    - **Docker** : le service optionnel `updater` (Watchtower, profil `updater`) télécharge les nouvelles images et redémarre les conteneurs ;
    - **serveur sans Docker** : la tâche planifiée `app:update:run` lance `deploy/update.sh` (git, composer, npm, migrations, redémarrage), avec journal en direct et retour à la version précédente en cas d'échec ;
    - **manuelle** : la page donne les commandes à lancer ;
  - la page se recharge sur la nouvelle version ; l'historique garde chaque mise à jour ;
  - les images Docker portent leur version (`git describe --tags`). Nouvelles variables : `UPDATE_REPOSITORY`, `UPDATE_METHOD`, `UPDATER_URL`, `UPDATER_TOKEN`, `UPDATE_SCRIPT`, `UPDATE_RESTART_COMMAND`. API : `GET /api/system/version`, `GET` et `POST /api/system/update`, `PUT /api/system/update/method`.

- **Layouts d'email** (Administration → Layouts d'email) : une enveloppe HTML commune (en-tête, pied de page, charte) avec l'emplacement `{{ content }}`.
  - Deux modèles de départ et un aperçu avec un contenu d'exemple.
  - Chaque template choisit son layout (ou aucun), avec un bouton **Aperçu** ; le choix est versionné.
  - Le composeur importe le HTML final, et l'API l'utilise pour un envoi par template. `GET /api/email_templates/{id}` renvoie `layout` et `renderedHtml`.
  - Démo : le layout « Charte Démo CRM » et le template « Confirmation de rendez-vous ».

- **Configuration LDAP dans l'administration** (Administration → Annuaire LDAP) :
  - URL, STARTTLS, base de recherche, compte de service, filtre, groupe des administrateurs et correspondance des attributs (utile pour Active Directory) ;
  - stockée en base, avec le mot de passe du compte de service chiffré, et appliquée sans redémarrage ;
  - bouton **Tester** avec aperçu des utilisateurs trouvés, avant d'enregistrer ;
  - les variables `LDAP_*` du `.env` restent la configuration par défaut (nouvelles : `LDAP_START_TLS`, `LDAP_ATTRIBUTE_*`), et on peut y revenir d'un clic.

- **Boîtes d'envoi** (Administration → Boîtes d'envoi) : de vrais comptes email depuis lesquels envoyer.
  - Envoi par leur serveur SMTP, ou par un fournisseur (API) : Brevo, Amazon SES, Mailjet, SendGrid, Postmark, Mailgun.
  - Copie de chaque email dans leur dossier « Envoyés » par IMAP : dossier détecté, ou créé s'il manque.
  - Configurations types : Gmail, Microsoft 365, OVHcloud, Infomaniak. Bouton « Tester la connexion » et envoi d'un email de test.
  - Mots de passe et DSN chiffrés en base (`MAILBOX_ENCRYPTION_KEY`), jamais renvoyés par l'API.
  - Rattachées aux applications : le composeur de l'application les propose dans la liste « De », à côté des adresses d'envoi. Une boîte peut aussi être ouverte à tous les utilisateurs.
  - API et widget : `mailbox` dans `POST /api/emails` et `setDraft()`, ou un `from` égal à l'adresse de la boîte. Clients Nuxt et Symfony mis à jour.
  - Historique : boîte utilisée, dossier de la copie, erreur de copie (l'email reste envoyé).
  - Démo : la « Boîte commerciale du CRM », avec un serveur IMAP de test (GreenMail).

- **Configuration initiale** : au premier lancement, tant qu'aucun compte n'existe, toutes les pages mènent à un formulaire de création du compte administrateur (email, nom, mot de passe). Il connecte ensuite l'administrateur et le guide vers les Réglages. `SETUP_TOKEN` (optionnel) protège cette étape sur une instance exposée. API : `GET` et `POST /api/setup`.

- **Variables de template** : `{{ client.prenom }}`, `{{ devis.numero }}`…
  - dans l'éditeur, le bouton **{x}** de la barre de texte insère une variable ; le bouton **Variables** leur donne un libellé et une valeur par défaut ;
  - dans le composeur, un encadré **Variables à compléter** liste celles qui restent, et l'envoi est bloqué tant qu'il en reste ;
  - l'application qui embarque le composeur passe les valeurs avec `setDraft({ template, variables })` : le composeur charge le template et fait le remplacement ;
  - par l'API, `POST /api/emails` accepte `template` et `variables` sans `subject` ni `htmlBody` ; une variable manquante donne une erreur `422`.
- Démo CRM : bouton « Relancer le devis (template + variables) ».
- **Web component** `<rocket-auth-composer>`, défini par `embed.js` : une balise suffit (`application-id`, `token-url`) ; propriété `draft` (applicable avant que le composeur soit prêt), événements `ready`, `sent`, `error`. Démo CRM a une page « Web component ».
- Bouton **Intégrer** dans le composeur (administrateurs) et **Code d'intégration** dans la page Applications : code prêt à coller pour une application (web component, JavaScript, Nuxt, endpoint de jeton), pré-rempli avec le brouillon en cours, template et variables compris.
- **Clients d'intégration**, dans `integrations/`, publiés dans des dépôts miroirs :
  - layer Nuxt `@rocket-auth/nuxt` : composant `<RocketMailerComposer>`, endpoint de jeton Nitro, `sendRocketMailerEmail()` ;
  - bundle Symfony `rocket-auth/rocket-auth-bundle` : endpoint de jeton, `RocketMailerClient` (envoi, templates, pièces jointes), fonction Twig `rocket_mailer_composer()`.
- Documentation « Intégrer dans votre application » : un guide par cas (Nuxt, Nuxt + API Platform, Symfony, autre stack).

- La démo (et Codespaces) lance aussi le site de documentation et le changelog, sur le port 3001.
- Sur Rocket Auth, `/docs` et `/changelog` redirigent vers la documentation et le changelog. Le tableau de bord a un raccourci « Nouveautés ».

### Modifié

- Le worker consomme aussi les tâches planifiées : `messenger:consume async scheduler_default` (à reprendre dans un service systemd ou supervisord existant).
- Le tableau de bord occupe toute la largeur de l'écran.

### Corrigé

- Éditeur de templates : le texte en cours de modification n'était pas enregistré si l'on cliquait sur « Enregistrer » sans quitter le bloc.
- Un nouveau template commence par un bloc de texte directement modifiable.

### Sécurité

- **Une application n'envoie plus jamais depuis les adresses de Rocket Auth** (adresses des Réglages, adresse par défaut de la plateforme) ni depuis l'adresse personnelle de l'utilisateur :
  - chaque application a son propre **expéditeur** (page Applications, obligatoire pour envoyer) ; l'adresse par défaut de la plateforme y est seulement proposée en suggestion ;
  - son composeur et ses appels à l'API n'ont droit qu'à cet expéditeur, à ses adresses autorisées et à **ses** boîtes d'envoi (pas celles ouvertes aux utilisateurs de Rocket Auth) ;
  - sans expéditeur, le composeur l'indique et l'API répond `422`. **À faire après la mise à jour : renseigner l'expéditeur de chaque application.**
- Les valeurs des variables sont insérées comme du texte (HTML échappé), et sur une seule ligne dans l'objet.

## [0.6.0] - 2026-09-24

Un tableau de bord pour suivre la plateforme d'un coup d'œil, et un journal pour retrouver n'importe quel email envoyé.

### Ajouté

- **Tableau de bord**, nouvelle page d'accueil :
  - envois des 30 derniers jours, comparés aux 30 jours précédents ;
  - taux de délivrabilité, file d'envoi, utilisateurs locaux et LDAP ;
  - intégrations, derniers envois et activité récente groupée par jour ;
  - barres de délivrabilité quotidienne et raccourcis.
- **État des services** pour les administrateurs : base de données, file d'envoi, relais SMTP, annuaire LDAP et stockage des pièces jointes.
- **Tous les envois** (administrateurs) et **Mes envois** :
  - recherche dans l'objet, l'adresse d'expédition et tous les destinataires ;
  - filtres par statut, application, expéditeur et période ;
  - pagination et filtres conservés dans l'URL.
- API : `GET /api/dashboard`, et les filtres `q`, `status`, `sender`, `application` et `createdAt` sur `GET /api/emails`.
- Actions rapides : « Nouvelle application » et « Nouvel utilisateur » ouvrent directement le formulaire.
- Ce changelog, publié dans le site de documentation.

### Modifié

- Le menu est organisé en sections (Messagerie, Administration).
- Après connexion, on arrive sur le tableau de bord au lieu du composeur.

### Corrigé

- Créer un template sans utilisateur connecté (commande console, import) faisait échouer l'enregistrement de sa version.

## [0.5.0] - 2026-09-24

Choisir l'adresse d'expédition.

### Ajouté

- Liste **« De »** dans le composeur :
  - elle propose les adresses d'expédition des Réglages, l'adresse par défaut présélectionnée ;
  - elle propose aussi l'adresse de l'utilisateur, sauf si les Réglages l'interdisent.
- Page **Réglages** (administrateurs) : adresses d'expédition, adresse par défaut et autorisation de l'adresse personnelle.
- `MAILER_DEFAULT_FROM` crée l'adresse par défaut à l'installation.
- Une application peut imposer l'adresse à la volée avec `setDraft({ from })` ou le champ `from` de l'API. Elle est limitée à ses **adresses d'expédition autorisées** (`contact@…` ou `*@domaine`).
- Quand l'email part d'une adresse partagée, les réponses reviennent à l'utilisateur (`Reply-To`).

### Supprimé

- La variable `MAILER_SENDER`, remplacée par `MAILER_DEFAULT_FROM`.

### Sécurité

- Toute adresse d'expédition qui n'est ni proposée à l'utilisateur ni autorisée pour l'application est refusée (`422`).

## [0.4.0] - 2026-09-24

Les pièces jointes.

### Ajouté

- Pièces jointes dans le composeur et dans le widget, par bouton ou par glisser-déposer. Par défaut : 10 fichiers, 10 Mo par fichier et 25 Mo par email.
- Une application peut joindre un document qu'elle a généré, par exemple un devis PDF : son backend le téléverse, puis la page le passe au widget avec `setDraft({ attachments })`.
- Téléchargement des pièces jointes depuis l'historique des envois.
- Commande `app:attachments:purge` pour supprimer les fichiers jamais envoyés.

### Corrigé

- Les derniers caractères tapés juste avant « Envoyer » pouvaient manquer dans l'email : le composeur lit maintenant le contenu de l'éditeur au moment de l'envoi.

### Sécurité

- Les exécutables et les scripts sont refusés (`.exe`, `.js`, `.bat`, `.ps1`…).
- Une pièce jointe n'est utilisable que par son propriétaire, et dans un seul email.

## [0.3.0] - 2026-09-24

La documentation.

### Ajouté

- Site de documentation (Nuxt UI + Nuxt Content), centré sur l'intégration du composeur embarqué :
  - déclaration de l'application et endpoint de jeton ;
  - widget, pré-remplissage et événements ;
  - exemples Vue, Nuxt et React ;
  - sécurité, protocole `postMessage` et dépannage.
- Pages API (authentification, emails) et administration (utilisateurs et LDAP, templates).

## [0.2.0] - 2026-09-24

Une démo prête à tester.

### Ajouté

- Démo en une commande : comptes locaux et LDAP, templates partagés, Mailpit, et **Démo CRM**, une application tierce qui embarque le composeur.
- Lancement dans **GitHub Codespaces** : ports publics et URLs configurés automatiquement.
- Vérification des scénarios de démo dans la CI.

### Corrigé

- En production, les emails restaient en file d'attente : il manquait le transport Doctrine de Messenger.
- Le proxy `/api` du front ne transmettait pas l'en-tête `Accept` : l'API répondait en JSON-LD au lieu de JSON.

## [0.1.0] - 2026-09-24

Première version.

### Ajouté

- **Utilisateurs** locaux ou **LDAP** (authentification par bind), synchronisation de l'annuaire en ligne de commande ou depuis l'interface, rôle administrateur par groupe LDAP.
- **Applications externes** : un jeton secret `raa_…`, dont seule l'empreinte est stockée, et l'**impersonation** avec `X-Impersonate-User`.
- **Composeur d'email** en texte enrichi (CKEditor 5).
- **Templates d'email** visuels (GrapesJS, preset newsletter) :
  - importables dans le composeur ;
  - privés ou partagés ;
  - **versionnés**, avec historique et restauration.
- **Composeur embarquable** dans une autre application (`embed.js`) :
  - jeton d'embed à durée de vie courte ;
  - origines autorisées (`frame-ancestors`) ;
  - protocole `postMessage` vérifié.
- Traçabilité : toutes les entités sont horodatées et attribuées (Timestampable, Blameable).
- Envoi asynchrone (Symfony Messenger), historique des envois.
- Images Docker publiées sur ghcr.io, CI (lint, tests, build, smoke test des images) et gitflow.

### Sécurité

- Une application n'obtient jamais le rôle administrateur, même en agissant au nom d'un administrateur.
- Un jeton d'embed n'accède qu'aux endpoints du composeur, et il est révoqué dès que l'application est désactivée.

[0.6.0]: https://github.com/fayouz/rocket-auth/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/fayouz/rocket-auth/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fayouz/rocket-auth/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fayouz/rocket-auth/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fayouz/rocket-auth/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fayouz/rocket-auth/releases/tag/v0.1.0
