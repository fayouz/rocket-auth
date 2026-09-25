# Changelog

Toutes les évolutions notables de Rocket Auth. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

## [0.1.0] - 2026-09-25

Première version de Rocket Auth, le fournisseur d'identité (SSO) du Middleware Rocket.

### Ajouté

- **Fournisseur OpenID Connect** :
  - découverte (`/.well-known/openid-configuration`), autorisation (`/oauth/authorize`), jetons (`/oauth/token`), `/oauth/userinfo`, clés publiques (`/oauth/jwks`), révocation (`/oauth/revoke`) et déconnexion (`/oauth/logout`, *RP-initiated logout*) ;
  - flux code d'autorisation avec PKCE (S256, obligatoire pour les clients publics), jetons de rafraîchissement renouvelés à chaque usage, identifiants client ; `prompt` (`none`, `login`, `consent`, `select_account`) et paramètre `iss` dans la réponse ;
  - jetons d'identité et d'accès signés en RS256 par une clé dédiée (`app:oidc:generate-key`, `--rotate`), distincte des sessions Rocket Auth ; durées de vie `OIDC_ACCESS_TOKEN_TTL`, `OIDC_ID_TOKEN_TTL`, `OIDC_REFRESH_TOKEN_TTL` ;
  - scopes `openid`, `profile`, `email`, `groups` (revendication `groups`) et `offline_access` ;
  - un code réutilisé ou un jeton de rafraîchissement rejoué révoque tous les jetons de l'utilisateur pour l'application ;
  - le front relaie `/oauth/…` et `/.well-known/…` : l'émetteur (`OIDC_ISSUER`) peut être l'adresse de l'interface.
- **Clients OAuth** (Administration → Clients OAuth) : confidentiels ou publics, adresses de retour et de déconnexion, scopes et flux autorisés, applications de confiance (sans écran d'autorisation), activation, secret affiché une seule fois et régénérable, date de dernière utilisation. API : `/api/oauth/clients`, `POST /api/oauth/clients/{id}/regenerate-secret`.
- **Page d'autorisation** : ce que l'application demande, compte connecté (« Ce n'est pas vous ? »), Autoriser ou Refuser ; l'accord est retenu. **Applications autorisées** : chaque utilisateur retire un accès, ce qui révoque les jetons de l'application (`GET` et `DELETE /api/consents`).
- **Page de déconnexion** : termine la session Rocket Auth et renvoie vers l'application si l'adresse est déclarée.
- **Suite Rocket** : `GET /api/suite/apps` publie les clients actifs qui ont une adresse (avec leur nom et leur icône), pour le sélecteur d'applications des briques en mode suite. Démo : Rocket Mailer, Rocket Cloud et Rocket Print déclarés comme clients (`DEMO_OAUTH_CLIENTS`), administrateurs dans le groupe `rocket-admins`.
- **Tableau de bord** : connexions aux applications et à Rocket Auth sur 30 jours, refus, sessions applicatives actives, clients OAuth, dernières connexions et activité.
- **Socle commun Rocket**, partagé avec Rocket Mailer, Rocket Cloud, Rocket Print et Rocket Doc Fusion : configuration initiale, comptes locaux, LDAP et groupes, fournisseurs OpenID Connect externes, applications externes et impersonation, tableau de bord extensible, sondes de santé, version et mises à jour (Docker, serveur sans Docker, manuelle), environnement de démo et Codespaces.
