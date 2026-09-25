/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'violet',
      neutral: 'zinc',
    },
  },
  rocket: {
    id: 'auth',
    name: 'Rocket Auth',
    icon: 'i-lucide-shield-check',
    // Login page subtitle.
    tagline: 'Un seul compte pour toutes les applications de l’entreprise.',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Mon compte', type: 'label' },
      { label: 'Applications autorisées', icon: 'i-lucide-shield-check', to: '/consents' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [
      { label: 'Clients OAuth', icon: 'i-lucide-app-window', to: '/clients' },
    ] as { label: string, icon: string, to: string }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [
      { label: 'Découverte OpenID', description: '/.well-known/openid-configuration', icon: 'i-lucide-radar', to: '/.well-known/openid-configuration', admin: true },
    ] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['La confiance est une clé qui ouvre bien des portes.', 'Proverbe'],
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Un mot de passe, c’est comme une brosse à dents : on ne le partage pas.', 'Sagesse informatique'],
    ] as [string, string][],
  },
})
