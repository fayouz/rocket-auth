---
title: Rocket Auth
description: Un seul compte pour toutes les applications de l'entreprise. Fournisseur OpenID Connect du Middleware Rocket.
seo:
  title: Rocket Auth — Documentation
---

::u-page-hero
---
orientation: horizontal
title: Un seul compte pour toutes vos applications.
---
#description
Rocket Auth est le fournisseur d'identité du Middleware Rocket : un serveur **OpenID Connect** qui connecte vos utilisateurs à Rocket Mailer, Rocket Cloud, Rocket Print, Rocket Doc Fusion et à toute application compatible. Comptes locaux, annuaire **LDAP**, groupes, écran d'autorisation et sélecteur d'applications de la suite.

#links
  :::u-button
  ---
  to: /administration/suite
  size: xl
  trailing-icon: i-lucide-arrow-right
  ---
  Brancher une application
  :::

  :::u-button
  ---
  to: /getting-started/introduction
  size: xl
  color: neutral
  variant: subtle
  icon: i-lucide-book-open
  ---
  Découvrir Rocket Auth
  :::

#default
  ```bash [Terminal]
  curl https://auth.exemple.com/.well-known/openid-configuration
  # → {
  #     "issuer": "https://auth.exemple.com",
  #     "authorization_endpoint": "https://auth.exemple.com/oauth/authorize",
  #     "token_endpoint": "https://auth.exemple.com/oauth/token",
  #     "userinfo_endpoint": "https://auth.exemple.com/oauth/userinfo",
  #     "jwks_uri": "https://auth.exemple.com/oauth/jwks",
  #     "end_session_endpoint": "https://auth.exemple.com/oauth/logout",
  #     …
  #   }
  ```
::

::u-page-section
#title
Ce que vous pouvez faire

#features
  :::u-page-feature
  ---
  icon: i-lucide-key-round
  to: /api/openid-connect
  ---
  #title
  OpenID Connect standard

  #description
  Code d'autorisation avec PKCE, jetons de rafraîchissement, identifiants client, userinfo, JWKS et déconnexion : toute application compatible s'y branche.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-app-window
  to: /administration/clients
  ---
  #title
  Clients OAuth

  #description
  Une fiche par application : adresses de retour, scopes, flux autorisés, secret affiché une seule fois, application de confiance.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-layout-grid
  to: /administration/suite
  ---
  #title
  La suite Rocket

  #description
  Les briques Rocket se connectent par Rocket Auth, reçoivent les groupes de l'utilisateur et affichent le sélecteur d'applications.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-shield-check
  to: /account/sign-in
  ---
  #title
  Consentement maîtrisé

  #description
  Chaque utilisateur voit ce qu'une application demande, l'autorise ou non, et retire un accès quand il le souhaite.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-users
  to: /administration/users-ldap
  ---
  #title
  Comptes locaux, LDAP et groupes

  #description
  Synchronisation avec votre annuaire, groupes transmis aux applications, rôle administrateur piloté par un groupe.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-activity
  to: /administration/dashboard
  ---
  #title
  Connexions suivies

  #description
  Connexions, autorisations et refus sur 30 jours, sessions applicatives actives et état des services.
  :::
::
