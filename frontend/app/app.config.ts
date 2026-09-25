/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'orange',
      neutral: 'zinc',
    },
  },
  rocket: {
    name: 'Rocket Auth',
    icon: 'i-lucide-rocket',
    // Login page subtitle.
    tagline: 'Connectez-vous avec votre compte local ou votre compte d’annuaire (LDAP).',
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [] as { label: string, icon: string, to: string }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['La simplicité est la sophistication suprême.', 'Léonard de Vinci'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
