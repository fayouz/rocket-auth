# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images). Elle affiche les URLs de la démo.
3. Dans l'onglet **Ports**, ouvre « Rocket Auth » (3100) ou « Documentation et changelog » (3101).

> ⚠️ Les mots de passe et les secrets de démo sont publics. Arrête le codespace quand tu as fini (menu Codespaces → *Stop codespace*).

Pour relancer la démo à la main : `bash demo/codespaces/start.sh`.

## En local

Pré-requis : Docker avec Compose v2.24 ou plus récent.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

Le service `demo-seed` prépare la base, charge les données de démo et synchronise l'annuaire LDAP, puis s'arrête : `docker compose -f compose.yaml -f compose.demo.yaml logs -f demo-seed`.

| Adresse | Contenu |
|---|---|
| http://localhost:3100 | Rocket Auth |
| http://localhost:3101 | Documentation, et le changelog sur `/changelog` |
| http://localhost:8100/.well-known/openid-configuration | Découverte OpenID Connect (l'émetteur est `http://localhost:8100`) |
| http://localhost:8100/api/docs | Documentation de l'API |

## Comptes

| Compte | Mot de passe | Type |
|---|---|---|
| `admin@example.org` | `demo-admin-password` | local, administrateur, groupe `rocket-admins` |
| `alice@example.org` | `demo-alice-password` | local |
| `marie.martin@example.org` | `password` | LDAP, administratrice via le groupe `rocket-admins` |
| `jean.dupont@example.org` | `password` | LDAP |

## Clients OAuth

Les briques de la suite sont déclarées par `DEMO_OAUTH_CLIENTS` (`compose.demo.yaml`), comme applications de confiance :

| Client ID | Secret | Adresse |
|---|---|---|
| `rocket-mailer` | `demo-rocket-mailer-client-secret` | http://localhost:3000 |
| `rocket-cloud` | `demo-rocket-cloud-client-secret` | http://localhost:3200 |
| `rocket-print` | `demo-rocket-print-client-secret` | http://localhost:3300 |

Format d'une entrée (séparées par `;`) : `clientId|Nom|secret|adresse de retour|adresse après déconnexion|adresse de l'application|icône`.

## Scénarios à tester

1. **Administration.** Connecte-toi avec `admin@example.org`, ouvre *Administration → Clients OAuth* : l'émetteur s'affiche en haut de la page. Modifie un client, régénère son secret, désactive-le.
2. **Découverte et sélecteur.** `curl http://localhost:8100/.well-known/openid-configuration`, puis `curl http://localhost:3100/api/suite/apps` : la liste des applications que les briques affichent dans leur menu. Un client désactivé, ou sans adresse, en disparaît.
3. **Connexion LDAP.** Connecte-toi avec `marie.martin@example.org` / `password` : elle est administratrice grâce à son groupe LDAP. Dans *Utilisateurs*, la colonne *Groupes* montre `rocket-admins`.
4. **Brancher une brique.** Lance par exemple Rocket Print en mode suite, avec `ROCKET_AUTH_URL=http://localhost:8100` et `ROCKET_AUTH_CLIENT_SECRET=demo-rocket-print-client-secret` (et `ROCKET_AUTH_INTERNAL_URL` si son API tourne dans un conteneur : l'adresse de Rocket Auth vue depuis ce conteneur). Sa page de connexion renvoie vers Rocket Auth.
5. **Application et impersonation.**
   ```bash
   curl http://localhost:3100/api/me \
     -H "Authorization: Bearer raa_demo_rocket_auth_do_not_use_in_production" \
     -H "X-Impersonate-User: admin@example.org" -H "Accept: application/json"
   ```
   L'application agit au nom de l'administrateur, sans obtenir `ROLE_ADMIN`.

## Réinitialiser

```bash
docker compose -f compose.yaml -f compose.demo.yaml down -v
```

> Cette démo utilise des mots de passe, des secrets de clients et un jeton d'application publics (`compose.demo.yaml`). Ne l'expose jamais sur Internet.
