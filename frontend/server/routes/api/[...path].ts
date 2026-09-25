/**
 * Same-origin access to the API: with NUXT_PUBLIC_API_BASE empty, the browser calls /api/** on the front,
 * which forwards to NUXT_API_INTERNAL_BASE. Used when only one public URL is available (e.g. Codespaces).
 */
export default defineEventHandler(event => proxyToApi(event))
