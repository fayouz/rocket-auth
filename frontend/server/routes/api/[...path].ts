/**
 * Same-origin access to the API: with NUXT_PUBLIC_API_BASE empty, the browser calls /api/** on the front,
 * which forwards to NUXT_API_INTERNAL_BASE. Used when only one public URL is available (e.g. Codespaces).
 */
export default defineEventHandler((event) => {
  const config = useRuntimeConfig()
  const target = config.apiInternalBase || config.public.apiBase
  if (!target) {
    throw createError({ statusCode: 503, statusMessage: 'API proxy not configured (NUXT_API_INTERNAL_BASE).' })
  }

  // h3 drops "Accept" when proxying; API Platform would then answer JSON-LD instead of JSON.
  return proxyRequest(event, `${target.replace(/\/$/, '')}${event.path}`, {
    headers: { accept: getRequestHeader(event, 'accept') ?? 'application/json' },
  })
})
