---
title: Rocket Auth
description: Envoyez des emails riches depuis Rocket Auth ou directement depuis vos propres applications, grâce au composeur embarquable.
seo:
  title: Rocket Auth — Documentation
---

::u-page-hero
---
orientation: horizontal
title: Des emails riches, partout dans vos applications.
---
#description
Rocket Auth centralise l'envoi d'emails : composeur en texte enrichi, templates visuels versionnés, comptes LDAP, et un **composeur embarquable** que vos applications intègrent en quelques lignes, en toute sécurité.

#links
  :::u-button
  ---
  to: /embed/overview
  size: xl
  trailing-icon: i-lucide-arrow-right
  ---
  Intégrer le composeur
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
  ```html [votre-page.html]
  <script src="https://mailer.exemple.com/embed.js"></script>
  <div id="mailer"></div>
  <script>
    RocketMailer.mount('#mailer', {
      baseUrl: 'https://mailer.exemple.com',
      applicationId: '0199…',
      getToken: () => fetch('/rocket-auth/token')
        .then(r => r.json()).then(d => d.token),
      onSent: email => console.log('Envoyé', email),
    })
  </script>
  ```
::

::u-page-section
#title
Ce que vous pouvez faire

#features
  :::u-page-feature
  ---
  icon: i-lucide-square-dashed-mouse-pointer
  to: /embed/overview
  ---
  #title
  Composeur embarquable

  #description
  Affichez le composeur dans votre CRM ou votre ERP : vos utilisateurs envoient en leur nom, sans quitter votre application.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-shield-check
  to: /embed/security
  ---
  #title
  Sécurisé par conception

  #description
  Secret côté serveur uniquement, jetons courts à portée restreinte, origines autorisées et jamais de droits administrateur.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-layout-template
  to: /administration/templates
  ---
  #title
  Templates visuels versionnés

  #description
  Créez vos emails avec GrapesJS, partagez-les, restaurez une version précédente et importez-les dans le composeur.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-users
  to: /administration/users-ldap
  ---
  #title
  Comptes locaux et LDAP

  #description
  Synchronisation avec votre annuaire, connexion par l'annuaire et rôle administrateur piloté par un groupe LDAP.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-key-round
  to: /api/authentication
  ---
  #title
  API complète

  #description
  Envoyez des emails et gérez les templates via une API REST documentée (OpenAPI), en tant qu'utilisateur ou application.
  :::

  :::u-page-feature
  ---
  icon: i-lucide-history
  to: /getting-started/introduction#traçabilité
  ---
  #title
  Traçabilité

  #description
  Chaque objet garde qui l'a créé et modifié, et chaque email l'utilisateur et l'application d'origine.
  :::
::
