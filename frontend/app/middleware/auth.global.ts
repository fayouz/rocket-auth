export default defineNuxtRouteMiddleware(async (to) => {
  // Public redirects to the documentation site (docs and changelog).
  if (to.path === '/changelog' || to.path === '/docs' || to.path.startsWith('/docs/')) return

  // End of a single sign-on: the page completes it itself.
  if (to.path === '/auth/callback') return
  // OpenID Connect provider: sign-out works without a session; an authorization with prompt=none must not
  // show the login page (the application gets "login_required").
  if (to.path === '/logout') return
  if (to.path === '/authorize' && !useAuth().token.value && String(to.query.prompt ?? '').split(' ').includes('none')) return

  const auth = useAuth()

  // First run: until an administrator exists, every page leads to the setup.
  if (!auth.token.value) {
    const setup = await useSetupStatus()
    if (setup.required && to.path !== '/setup') return navigateTo('/setup')
    if (!setup.required && to.path === '/setup') return navigateTo('/login')
    if (to.path === '/setup') return
  }
  else if (to.path === '/setup') {
    return navigateTo('/')
  }

  if (to.path === '/login') {
    return auth.token.value ? navigateTo('/') : undefined
  }

  if (!auth.token.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }

  if (!auth.me.value) {
    try {
      await auth.fetchMe()
    }
    catch {
      return navigateTo('/login')
    }
  }

  if (to.meta.admin && !auth.isAdmin.value) {
    return navigateTo('/')
  }
})
