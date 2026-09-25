export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/ui', '@nuxt/eslint'],
  // Back-office app: auth lives client-side.
  ssr: false,
  css: ['~/assets/css/main.css'],
  // No third-party font CDNs: system fonts only.
  ui: { fonts: false },
  devtools: { enabled: false },
  app: {
    head: {
      title: 'Rocket Auth',
      htmlAttrs: { lang: 'fr' },
    },
  },
  runtimeConfig: {
    // Server-to-server URL of the API (e.g. http://api:80 inside Docker); falls back to the public one.
    apiInternalBase: '',
    public: {
      apiBase: 'http://localhost:8000',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-auth/tree/develop/docs/content',
      changelogUrl: 'https://github.com/fayouz/rocket-auth/blob/develop/CHANGELOG.md',
      // Version of the interface: NUXT_PUBLIC_APP_VERSION, at build time (deploy/update.sh) or at runtime (Docker image).
      appVersion: process.env.NUXT_PUBLIC_APP_VERSION || 'dev',
    },
  },
  icon: {
    serverBundle: { collections: ['lucide'] },
    clientBundle: { scan: true },
  },
})
