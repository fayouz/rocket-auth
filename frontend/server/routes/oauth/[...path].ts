/**
 * The OpenID Connect endpoints of the API (/oauth/**, /.well-known/**) are also reachable through the interface:
 * with a single public URL (Codespaces, reverse proxy on one host), the issuer can be the interface's origin.
 */
export default defineEventHandler(event => proxyToApi(event))
