<script setup lang="ts">
import type { Dashboard, DashboardActivity, DashboardKpi, ServiceStatus } from '~/types/api'

const app = useAppConfig().rocket
useHead({ title: `Tableau de bord · ${app.name}` })

const api = useApi()
const auth = useAuth()
const config = useRuntimeConfig()
const toast = useToast()

const { data, status, refresh } = await useAsyncData('dashboard', () => api<Dashboard>('/api/dashboard'))

// Relative times ("il y a 2 min") and the periodic refresh share one clock.
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => {
    now.value = new Date()
    if (now.value.getTime() - new Date(data.value?.generatedAt ?? 0).getTime() >= 60_000) refresh()
  }, 15_000)
})
onBeforeUnmount(() => clearInterval(timer))

const isAdmin = computed(() => auth.isAdmin.value)
const firstName = computed(() => auth.me.value?.user?.firstName || auth.me.value?.user?.displayName || '')
const today = computed(() => capitalize(new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now.value)))

const HEALTH: Record<ServiceStatus, { label: string, dot: string }> = {
  operational: { label: 'Opérationnel', dot: 'bg-success' },
  degraded: { label: 'Dégradé', dot: 'bg-warning' },
  down: { label: 'Hors service', dot: 'bg-error' },
  disabled: { label: 'Désactivé', dot: 'bg-neutral-400 dark:bg-neutral-600' },
  unknown: { label: 'Non vérifié', dot: 'bg-neutral-300 dark:bg-neutral-700' },
}

// LDAP and OpenID Connect providers are checked over the network by the worker every 5 minutes; "Vérifier" runs them now.
const checking = ref(false)
async function checkServices() {
  if (!data.value) return
  checking.value = true
  try {
    data.value.health = await api<Dashboard['health']>('/api/health/check', { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: 'Vérification impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    checking.value = false
  }
}
const platformStatus = computed(() => ({
  operational: 'Systèmes opérationnels',
  degraded: 'Service dégradé',
  down: 'Incident en cours',
}[data.value?.health.status ?? 'operational']))

// --- KPIs ---------------------------------------------------------------------------------------

function kpiValue(kpi: DashboardKpi): string {
  if (kpi.format === 'percent') return formatPercent(kpi.value)
  if (kpi.format === 'bytes') return kpi.value === null ? '—' : formatSize(kpi.value)
  return kpi.value === null ? '—' : formatNumber(kpi.value)
}
function kpiDelta(kpi: DashboardKpi): number | null {
  if (kpi.previous === undefined || !kpi.previous || kpi.value === null) return null
  return Math.round(((kpi.value - kpi.previous) / kpi.previous) * 100)
}
function seriesValues(key: string): number[] {
  return data.value?.daily.map(d => Number(d[key] ?? 0)) ?? []
}

// --- Actions ------------------------------------------------------------------------------------

const syncing = ref(false)
async function syncLdap() {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number }>('/api/ldap/sync', { method: 'POST' })
    toast.add({ title: 'Annuaire synchronisé', description: `${report.created} créé(s), ${report.updated} mis à jour, ${report.disabled} désactivé(s)`, color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Synchronisation impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

const quickActions = computed(() => [
  ...(data.value?.quickActions ?? []),
  ...(isAdmin.value
    ? [
        { label: 'Nouvelle application', icon: 'i-lucide-plug', to: '/applications?new=1', tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
        { label: 'Nouvel utilisateur', icon: 'i-lucide-user-plus', to: '/users?new=1', tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
      ]
    : []),
].slice(0, 4))

// --- Activity -----------------------------------------------------------------------------------

const activityByDay = computed(() => {
  const groups = new Map<string, DashboardActivity[]>()
  for (const event of data.value?.activity ?? []) {
    const day = dayLabel(event.at)
    groups.set(day, [...(groups.get(day) ?? []), event])
  }
  return [...groups.entries()]
})

function dayLabel(value: string): string {
  const date = new Date(value)
  const days = Math.round((new Date(now.value).setHours(0, 0, 0, 0) - new Date(date).setHours(0, 0, 0, 0)) / 86_400_000)
  if (days === 0) return 'Aujourd’hui'
  if (days === 1) return 'Hier'
  return capitalize(new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(date))
}

function time(value: string): string {
  return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

// --- Daily chart (stacked series) ---------------------------------------------------------------

const bars = computed(() => {
  const series = data.value?.series ?? []
  const daily = data.value?.daily ?? []
  const totals = daily.map(d => series.reduce((sum, s) => sum + Number(d[s.key] ?? 0), 0))
  const max = Math.max(...totals, 1)
  return daily.map((d, i) => ({
    date: String(d.date),
    total: totals[i]!,
    segments: series.map(s => ({ key: s.key, color: s.color, height: (100 * Number(d[s.key] ?? 0)) / max })),
    tooltip: `${new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(new Date(String(d.date)))} · ${series.map(s => `${d[s.key] ?? 0} ${s.label.toLowerCase()}`).join(', ')}`,
  }))
})

// --- Applications -------------------------------------------------------------------------------

function applicationState(application: NonNullable<Dashboard['applications']>[number]) {
  if (!application.enabled) return { label: 'Désactivée', color: 'neutral' as const }
  if (application.lastUsedAt && now.value.getTime() - new Date(application.lastUsedAt).getTime() < 7 * 86_400_000) {
    return { label: 'Active', color: 'success' as const }
  }
  return { label: 'Inactive', color: 'warning' as const }
}

// --- Shortcuts ----------------------------------------------------------------------------------

const shortcuts = computed(() => [
  { label: 'Documentation', description: 'Guides d’utilisation et d’administration', icon: 'i-lucide-book-open', to: config.public.docsUrl, external: true },
  { label: 'Nouveautés', description: 'Changelog des versions', icon: 'i-lucide-history', to: config.public.changelogUrl, external: true },
  { label: 'API', description: 'OpenAPI et bac à sable', icon: 'i-lucide-braces', to: `${config.public.apiBase}/api/docs`, external: true },
  ...app.shortcuts.filter(s => !s.admin || isAdmin.value).map(s => ({ ...s, external: s.to.startsWith('http') })),
  ...(isAdmin.value
    ? [{ label: 'Applications', description: 'Jetons et impersonation', icon: 'i-lucide-key-round', to: '/applications', external: false }]
    : []),
])

function serviceMetric(service: NonNullable<Dashboard['health']['services']>[number]): string | null {
  if (service.id === 'database') return service.latencyMs !== undefined ? `${service.latencyMs} ms` : null
  if (service.id === 'queue') return `${service.queued ?? 0} en attente`
  if (service.id === 'storage') return service.freeBytes ? `${formatSize(service.freeBytes)} libres` : null
  if (service.id === 'ldap') {
    return service.check?.checkedAt
      ? `Vérifié ${timeAgo(service.check.checkedAt, now.value)}${service.latencyMs ? ` · ${service.latencyMs} ms` : ''}`
      : service.lastSyncAt ? `Synchro ${timeAgo(service.lastSyncAt, now.value)}` : null
  }
  return service.total ? `${service.total - (service.failing ?? 0)}/${service.total} OK` : null
}
</script>

<template>
  <UDashboardPanel id="dashboard">
    <template #header>
      <UDashboardNavbar title="Tableau de bord">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" color="neutral" variant="ghost" aria-label="Rafraîchir" :loading="status === 'pending'" @click="refresh()" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="data" class="flex w-full flex-col gap-6" data-testid="dashboard">
        <!-- Greeting, status and illustration -->
        <div class="grid gap-4 lg:grid-cols-5">
          <div class="flex flex-col justify-center gap-3 lg:col-span-3">
            <div>
              <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">
                Bonjour{{ firstName ? `, ${firstName}` : '' }} 👋
              </h1>
              <p class="mt-1 text-muted">
                {{ data.scope === 'platform' ? 'Voici l’état de votre plateforme en temps réel.' : 'Voici votre activité.' }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1" data-testid="platform-status">
                <span class="relative flex size-2">
                  <span class="absolute inline-flex size-full animate-ping rounded-full opacity-60" :class="HEALTH[data.health.status].dot" />
                  <span class="relative inline-flex size-2 rounded-full" :class="HEALTH[data.health.status].dot" />
                </span>
                {{ platformStatus }}
              </span>
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1 text-muted">
                <UIcon name="i-lucide-refresh-cw" class="size-3.5" />
                Mis à jour {{ timeAgo(data.generatedAt, now) }}
              </span>
              <span class="inline-flex items-center gap-2 rounded-full border border-default px-3 py-1 text-muted">
                <UIcon name="i-lucide-calendar" class="size-3.5" />
                {{ today }}
              </span>
            </div>
          </div>
          <DashboardHeroBanner class="hidden sm:block lg:col-span-2" />
        </div>

        <!-- Quick actions -->
        <section v-if="quickActions.length || isAdmin" aria-labelledby="quick-actions">
          <h2 id="quick-actions" class="mb-2 text-sm font-medium text-muted">
            Actions rapides
          </h2>
          <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <ULink
              v-for="action in quickActions"
              :key="action.label"
              :to="action.to"
              class="flex items-center gap-3 rounded-lg border border-default bg-default p-3 font-medium text-highlighted transition hover:border-primary/40 hover:bg-elevated/50"
            >
              <span class="flex size-9 shrink-0 items-center justify-center rounded-md" :class="action.tone">
                <UIcon :name="action.icon" class="size-5" />
              </span>
              {{ action.label }}
            </ULink>
            <UButton
              v-if="isAdmin && data.health.services?.find(s => s.id === 'ldap')?.status !== 'disabled'"
              icon="i-lucide-folder-sync"
              label="Synchroniser LDAP"
              color="neutral"
              variant="outline"
              class="justify-center"
              :loading="syncing"
              @click="syncLdap"
            />
          </div>
        </section>

        <!-- KPIs -->
        <div v-if="data.kpis.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <DashboardKpiCard
            v-for="kpi in data.kpis"
            :key="kpi.id"
            :title="kpi.label"
            :value="kpiValue(kpi)"
            :icon="kpi.icon"
            :tone="kpi.tone"
            :data-testid="`kpi-${kpi.id}`"
          >
            <DashboardSparkline v-if="kpi.series" :values="seriesValues(kpi.series)" />
            <UProgress v-if="kpi.progress !== undefined" :model-value="kpi.progress" :color="kpi.progress >= 90 ? 'warning' : 'primary'" size="sm" class="mt-auto" />
            <p v-if="kpi.detail" class="text-sm text-muted">
              {{ kpi.detail }}
            </p>
            <template v-if="kpiDelta(kpi) !== null || kpi.legend?.length" #footer>
              <span v-if="kpiDelta(kpi) !== null" :class="kpiDelta(kpi)! >= 0 ? 'text-success' : 'text-error'" class="inline-flex items-center gap-1 font-medium">
                <UIcon :name="kpiDelta(kpi)! >= 0 ? 'i-lucide-trending-up' : 'i-lucide-trending-down'" class="size-3.5" />
                {{ kpiDelta(kpi)! > 0 ? '+' : '' }}{{ kpiDelta(kpi) }} % vs 30 jours précédents
              </span>
              <span v-for="item in kpi.legend" :key="item.label" class="inline-flex items-center gap-1.5">
                <span class="size-2 rounded-full" :class="item.color" />{{ item.label }}
              </span>
            </template>
          </DashboardKpiCard>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
          <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">
            <!-- Latest items of the domain module -->
            <UCard v-if="data.recent" :ui="{ header: 'flex items-center justify-between gap-2', body: 'p-0 sm:p-0' }">
              <template #header>
                <h2 class="font-semibold text-highlighted">
                  {{ data.recent.title }}
                </h2>
                <UButton v-if="data.recent.link" label="Tout voir" :to="data.recent.link" color="neutral" variant="ghost" size="sm" trailing-icon="i-lucide-arrow-right" />
              </template>
              <ul v-if="data.recent.items.length" class="divide-y divide-default" data-testid="recent-items">
                <li v-for="item in data.recent.items" :key="item.id" class="flex items-center gap-3 px-4 py-3 sm:px-6">
                  <div class="min-w-0 flex-1">
                    <ULink v-if="item.link" :to="item.link" class="block truncate font-medium text-highlighted hover:underline">
                      {{ item.title }}
                    </ULink>
                    <p v-else class="truncate font-medium text-highlighted">
                      {{ item.title }}
                    </p>
                    <p class="truncate text-xs text-muted">
                      {{ item.subtitle }}
                    </p>
                  </div>
                  <span v-if="item.at" class="hidden text-xs text-muted sm:block">{{ timeAgo(item.at, now) }}</span>
                  <UBadge v-if="item.badge" :label="item.badge" :color="item.badgeColor ?? 'neutral'" variant="subtle" size="sm" />
                </li>
              </ul>
              <p v-else class="p-8 text-center text-sm text-muted">
                {{ data.recent.empty }}
              </p>
            </UCard>

            <!-- Integrations -->
            <UCard v-if="data.applications" :ui="{ header: 'flex items-center justify-between gap-2' }">
              <template #header>
                <h2 class="font-semibold text-highlighted">
                  Intégrations
                </h2>
                <UButton label="Gérer" to="/applications" color="neutral" variant="ghost" size="sm" trailing-icon="i-lucide-arrow-right" />
              </template>
              <div v-if="data.applications.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="integrations">
                <div
                  v-for="application in data.applications.slice(0, 6)"
                  :key="application.id"
                  class="flex flex-col gap-2 rounded-lg border border-default p-3"
                >
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                      <UAvatar :text="application.name.slice(0, 2).toUpperCase()" size="sm" class="bg-primary/10 text-primary" />
                      <span class="truncate font-medium text-highlighted">{{ application.name }}</span>
                    </div>
                    <UBadge v-bind="applicationState(application)" variant="subtle" size="sm" />
                  </div>
                  <div class="flex items-center justify-between text-xs text-muted">
                    <span>{{ application.canImpersonate ? 'Impersonation' : 'Identification seule' }}</span>
                    <span>{{ timeAgo(application.lastUsedAt, now) }}</span>
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-muted">
                Aucune application : créez-en une pour appeler l’API depuis vos outils.
              </p>
            </UCard>

            <div class="grid gap-6" :class="data.health.services && data.series.length ? 'lg:grid-cols-2' : ''">
              <!-- Services -->
              <UCard v-if="data.health.services">
                <template #header>
                  <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-highlighted">
                      État des services
                    </h2>
                    <UButton
                      label="Vérifier"
                      icon="i-lucide-activity"
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      :loading="checking"
                      data-testid="check-services"
                      @click="checkServices"
                    />
                  </div>
                </template>
                <ul class="flex flex-col gap-4" data-testid="services">
                  <li v-for="service in data.health.services" :key="service.id" class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between gap-2">
                      <span class="inline-flex items-center gap-2 font-medium text-highlighted">
                        <span class="size-2 rounded-full" :class="HEALTH[service.status].dot" />
                        {{ service.label }}
                      </span>
                      <span class="text-xs text-muted">{{ serviceMetric(service) ?? HEALTH[service.status].label }}</span>
                    </div>
                    <p class="truncate text-xs text-muted" :title="service.detail">
                      {{ service.detail }}
                    </p>
                    <p v-if="service.check?.failingSince && service.status === 'down'" class="text-xs text-error">
                      En échec depuis {{ timeAgo(service.check.failingSince, now) }}
                    </p>
                    <ul v-if="service.items?.length" class="ms-4 flex flex-col gap-1" :data-testid="`${service.id}-health`">
                      <li v-for="item in service.items" :key="item.id" class="flex items-start gap-2 text-xs">
                        <span class="mt-1 size-1.5 shrink-0 rounded-full" :class="HEALTH[item.status].dot" />
                        <span class="min-w-0">
                          <span class="font-medium text-default">{{ item.name }}</span>
                          <span class="block truncate text-muted" :title="item.check?.detail">
                            {{ item.check?.status === 'operational' ? 'OK' : item.check?.detail ?? 'Non vérifié' }}
                          </span>
                        </span>
                      </li>
                    </ul>
                    <UProgress
                      v-if="service.usagePercent !== undefined && service.usagePercent !== null"
                      :model-value="service.usagePercent"
                      :color="service.usagePercent >= 90 ? 'warning' : 'primary'"
                      size="xs"
                    />
                  </li>
                </ul>
              </UCard>

              <!-- Daily activity -->
              <UCard v-if="data.series.length">
                <template #header>
                  <h2 class="font-semibold text-highlighted">
                    Activité ({{ data.days }} derniers jours)
                  </h2>
                </template>
                <div class="flex flex-col gap-4">
                  <div class="flex h-24 items-end gap-[3px]" data-testid="daily-bars">
                    <UTooltip v-for="bar in bars" :key="bar.date" :text="bar.tooltip">
                      <div class="flex h-full min-w-0 flex-1 flex-col-reverse overflow-hidden rounded-sm" :class="bar.total ? '' : 'bg-accented/50'">
                        <div v-for="segment in bar.segments" :key="segment.key" :class="segment.color" :style="{ height: `${segment.height}%` }" />
                      </div>
                    </UTooltip>
                  </div>
                  <div class="flex justify-between text-xs text-muted">
                    <span>Il y a {{ data.days }} jours</span>
                    <span>Aujourd’hui</span>
                  </div>
                  <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span v-for="series in data.series" :key="series.key" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm" :class="series.color" />{{ series.label }}</span>
                  </div>
                </div>
              </UCard>
            </div>
          </div>

          <!-- Activity timeline -->
          <UCard class="xl:row-span-2" :ui="{ body: 'flex flex-col gap-5' }">
            <template #header>
              <h2 class="font-semibold text-highlighted">
                Activité récente
              </h2>
            </template>
            <section v-for="[day, events] in activityByDay" :key="day" data-testid="activity-day">
              <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted">
                {{ day }}
              </h3>
              <ol class="relative flex flex-col gap-4 border-l border-default pl-5">
                <li v-for="(event, index) in events" :key="index" class="relative">
                  <span class="absolute -left-[33px] flex size-6 items-center justify-center rounded-full ring-4 ring-(--ui-bg)" :class="event.color">
                    <UIcon :name="event.icon" class="size-3.5" />
                  </span>
                  <p class="text-xs text-muted">
                    {{ event.label }} · {{ time(event.at) }}
                  </p>
                  <ULink v-if="event.link" :to="event.link" class="block truncate font-medium text-highlighted hover:underline">
                    {{ event.title }}
                  </ULink>
                  <p v-else class="truncate font-medium text-highlighted">
                    {{ event.title }}
                  </p>
                  <p v-if="event.actor" class="truncate text-xs text-muted">
                    {{ event.actor }}
                  </p>
                </li>
              </ol>
            </section>
            <p v-if="!activityByDay.length" class="text-sm text-muted">
              Rien à signaler pour l’instant.
            </p>
          </UCard>
        </div>

        <!-- Shortcuts -->
        <section aria-labelledby="shortcuts">
          <h2 id="shortcuts" class="mb-2 text-sm font-medium text-muted">
            Services & raccourcis
          </h2>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <ULink
              v-for="shortcut in shortcuts"
              :key="shortcut.label"
              :to="shortcut.to"
              :target="shortcut.external ? '_blank' : undefined"
              class="flex items-center gap-3 rounded-lg border border-default p-3 transition hover:border-primary/40 hover:bg-elevated/50"
            >
              <UIcon :name="shortcut.icon" class="size-5 shrink-0 text-primary" />
              <span class="min-w-0">
                <span class="flex items-center gap-1 font-medium text-highlighted">
                  {{ shortcut.label }}
                  <UIcon v-if="shortcut.external" name="i-lucide-external-link" class="size-3 text-muted" />
                </span>
                <span class="block truncate text-xs text-muted">{{ shortcut.description }}</span>
              </span>
            </ULink>
          </div>
        </section>
      </div>

      <div v-else-if="status === 'error'" class="flex flex-col items-center gap-3 p-10 text-muted">
        Le tableau de bord n’a pas pu être chargé.
        <UButton label="Réessayer" icon="i-lucide-refresh-cw" color="neutral" variant="outline" @click="refresh()" />
      </div>
    </template>
  </UDashboardPanel>
</template>
