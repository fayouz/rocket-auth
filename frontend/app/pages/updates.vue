<script setup lang="ts">
import type { AppVersionInfo, UpdateMethod, UpdateRun } from '~/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Mises à jour · ${appName}` })

const config = useRuntimeConfig()
const api = useApi()
const toast = useToast()
const { update, frontVersion, fetchUpdate } = useAppVersion()

const checking = ref(false)
const savingMethod = ref(false)
const confirmOpen = ref(false)
/** Docker: waiting for the new version to answer. */
const waitingVersion = ref(false)
const stalled = ref(false)
let poll: ReturnType<typeof setInterval> | undefined

// Checks against GitHub once per page load (cached an hour by the API); "Vérifier maintenant" forces it.
await useAsyncData('update-status', () => fetchUpdate())

const current = computed(() => update.value?.current)
const latest = computed(() => update.value?.latest ?? null)
const run = computed(() => update.value?.run ?? null)
const method = computed(() => update.value?.method ?? 'manual')
const versionsDiffer = computed(() => !!current.value && current.value.version !== frontVersion.value && frontVersion.value !== 'dev')
const running = computed(() => waitingVersion.value || (!!run.value && ['requested', 'running', 'started'].includes(run.value.status)))
const canStart = computed(() => {
  const methods = update.value?.methods
  if (!methods || running.value) return false
  if (method.value === 'docker') return methods.docker.configured
  if (method.value === 'script') return methods.script.installed
  return false
})

const METHOD_LABELS: Record<UpdateMethod, string> = {
  docker: 'Docker (Watchtower)',
  script: 'Serveur sans Docker (script)',
  manual: 'Manuelle',
}
const methodItems = computed(() => (['docker', 'script', 'manual'] as const).map(value => ({
  value,
  label: METHOD_LABELS[value] + (update.value?.defaultMethod === value ? ' — par défaut' : ''),
  description: {
    docker: 'Watchtower télécharge les nouvelles images et redémarre les conteneurs.',
    script: 'Une tâche planifiée lance deploy/update.sh : git, composer, npm, migrations, redémarrage.',
    manual: 'Pas de bouton : la page donne les commandes à lancer sur le serveur.',
  }[value],
})))

const RUN_STATUS: Record<UpdateRun['status'], { label: string, color: 'neutral' | 'info' | 'success' | 'error' | 'warning' }> = {
  requested: { label: 'En attente de la tâche planifiée', color: 'info' },
  running: { label: 'En cours', color: 'info' },
  started: { label: 'Envoyée à Watchtower', color: 'info' },
  succeeded: { label: 'Installée', color: 'success' },
  failed: { label: 'Échec', color: 'error' },
  cancelled: { label: 'Annulée', color: 'neutral' },
}

const cronLine = computed(() => `* * * * * cd ${(update.value?.methods.script.script ?? '/srv/rocket-auth/deploy/update.sh').replace(/\/deploy\/[^/]+$/, '')}/backend && php bin/console app:update:run`)

async function check() {
  checking.value = true
  try {
    await fetchUpdate(true)
  }
  catch (error) {
    toast.add({ title: 'Vérification impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    checking.value = false
  }
}

async function setMethod(value: UpdateMethod | null) {
  savingMethod.value = true
  try {
    await api('/api/system/update/method', { method: 'PUT', body: { method: value } })
    await fetchUpdate()
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    savingMethod.value = false
  }
}

function reloadOn(version: string) {
  clearInterval(poll)
  toast.add({ title: `${appName} ${version} est installé`, color: 'success', icon: 'i-lucide-party-popper' })
  setTimeout(() => window.location.reload(), 1500)
}

/** Docker: the API and the interface restart; wait for the new version to answer, then reload the page. */
function waitForNewVersion(before: string | undefined) {
  waitingVersion.value = true
  stalled.value = false
  const since = Date.now()
  clearInterval(poll)
  poll = setInterval(async () => {
    try {
      const { version } = await api<AppVersionInfo>('/api/system/version')
      if (version !== before) return reloadOn(version)
    }
    catch {
      // Restarting.
    }
    if (Date.now() - since > 5 * 60_000) stalled.value = true
  }, 5000)
}

/** Script: follow the run (status and log) until it ends. */
function followRun() {
  clearInterval(poll)
  poll = setInterval(async () => {
    try {
      const status = await fetchUpdate()
      if (!status.run || ['requested', 'running'].includes(status.run.status)) return
      clearInterval(poll)
      if (status.run.status === 'succeeded') reloadOn(status.current.version)
    }
    catch {
      // The API may restart at the end of the script.
    }
  }, 3000)
}

async function start() {
  confirmOpen.value = false
  const before = current.value?.version
  try {
    await api('/api/system/update', { method: 'POST' })
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return
  }
  if (method.value === 'docker') {
    waitForNewVersion(before)
  }
  else {
    await fetchUpdate()
    followRun()
  }
}

async function cancel() {
  try {
    await api('/api/system/update/cancel', { method: 'POST' })
    clearInterval(poll)
    await fetchUpdate()
  }
  catch (error) {
    toast.add({ title: 'Annulation impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

// Coming back to the page while an update runs.
onMounted(() => {
  if (run.value?.status === 'requested' || run.value?.status === 'running') followRun()
  else if (run.value?.status === 'started') waitForNewVersion(run.value.fromVersion)
})
onBeforeUnmount(() => clearInterval(poll))

const logBox = ref<HTMLElement | null>(null)
watch(() => run.value?.log, () => nextTick(() => {
  if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
}))
</script>

<template>
  <UDashboardPanel id="updates">
    <template #header>
      <UDashboardNavbar title="Mises à jour">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            label="Vérifier maintenant"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            :loading="checking"
            :disabled="!update?.checkEnabled"
            data-testid="check-updates"
            @click="check"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="update" class="flex max-w-3xl flex-col gap-6">
        <UPageCard title="Version installée" variant="subtle">
          <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="text-3xl font-semibold" data-testid="installed-version">
                {{ current?.version }}
              </p>
              <p v-if="versionsDiffer" class="text-sm text-muted">
                Interface : {{ frontVersion }}
              </p>
            </div>
            <UButton label="Changelog" icon="i-lucide-scroll-text" color="neutral" variant="link" :to="config.public.changelogUrl" target="_blank" />
          </div>
          <p v-if="current?.release === null" class="text-sm text-muted">
            Build de développement ou d’une branche : il n’a pas de numéro de version à comparer aux versions publiées.
          </p>
        </UPageCard>

        <UAlert v-if="update.error" color="warning" variant="subtle" icon="i-lucide-cloud-off" title="Vérification impossible" :description="update.error" />
        <UAlert
          v-else-if="!update.checkEnabled"
          color="neutral"
          variant="subtle"
          icon="i-lucide-bell-off"
          title="Vérification des mises à jour désactivée"
          :description="`UPDATE_REPOSITORY est vide : ${appName} ne consulte pas les versions publiées.`"
        />
        <UAlert
          v-else-if="!latest"
          color="neutral"
          variant="subtle"
          icon="i-lucide-package-search"
          title="Aucune version publiée pour l’instant"
          :description="`Aucune version n’est publiée sur ${update.repositoryUrl}.`"
        />

        <UPageCard
          v-if="latest"
          :title="update.updateAvailable ? `${appName} ${latest.version} est disponible` : update.updateAvailable === false ? `${appName} est à jour` : `Dernière version publiée : ${latest.version}`"
          :description="latest.name !== latest.version && latest.name !== `v${latest.version}` ? latest.name : undefined"
          :icon="update.updateAvailable ? 'i-lucide-sparkles' : 'i-lucide-circle-check'"
          :variant="update.updateAvailable ? 'soft' : 'subtle'"
          data-testid="latest-release"
        >
          <p class="text-sm text-muted">
            {{ latest.publishedAt ? `Version ${latest.version}, publiée le ${formatDate(latest.publishedAt)}.` : `Version ${latest.version}.` }}
            <ULink :to="latest.url" target="_blank" class="text-primary">
              Voir la version
            </ULink>
          </p>
          <div v-if="latest.notes && update.updateAvailable" class="max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-default bg-default p-3 text-sm">
            {{ latest.notes }}
          </div>
        </UPageCard>

        <UPageCard title="Méthode de mise à jour" :description="`Selon l’installation de ${appName}.`" icon="i-lucide-settings-2" variant="subtle">
          <URadioGroup
            :model-value="method"
            :items="methodItems"
            :disabled="savingMethod || running"
            variant="table"
            data-testid="update-method"
            @update:model-value="(value) => setMethod(value as UpdateMethod)"
          />
          <div v-if="update.methodSource === 'database' && update.defaultMethod !== method">
            <UButton
              :label="`Revenir à la méthode par défaut (${METHOD_LABELS[update.defaultMethod]})`"
              color="neutral"
              variant="link"
              size="sm"
              class="px-0"
              @click="setMethod(null)"
            />
          </div>
        </UPageCard>

        <!-- Docker -->
        <UPageCard v-if="method === 'docker'" title="Mettre à jour" icon="i-lucide-container" variant="subtle" data-testid="update-docker">
          <template v-if="update.methods.docker.configured">
            <p class="text-sm text-muted">
              Watchtower télécharge les dernières images publiées, puis redémarre l’API, l’envoi et l’interface.
            </p>
          </template>
          <template v-else>
            <UAlert
              color="warning"
              variant="subtle"
              icon="i-lucide-plug-zap"
              title="Le service updater n’est pas configuré"
              description="Définissez UPDATER_TOKEN, utilisez les images publiées (API_IMAGE, FRONT_IMAGE), puis lancez : docker compose --profile updater up -d"
            />
          </template>
          <UAlert
            v-if="waitingVersion || run?.status === 'started'"
            :color="stalled ? 'warning' : 'info'"
            variant="subtle"
            :icon="stalled ? 'i-lucide-triangle-alert' : 'i-lucide-loader-circle'"
            :ui="{ icon: stalled ? '' : 'animate-spin' }"
            :title="stalled ? 'La nouvelle version ne répond toujours pas' : 'Mise à jour en cours…'"
            :description="stalled
              ? 'Aucune nouvelle version n’a démarré. Vérifiez les journaux du service de mise à jour (docker compose logs updater), et que les services utilisent les images publiées (API_IMAGE, FRONT_IMAGE).'
              : `Téléchargement des nouvelles images, puis redémarrage des services : ${appName} est indisponible une à deux minutes. La page se recharge toute seule.`"
            data-testid="update-progress"
          />
        </UPageCard>

        <!-- Server without Docker -->
        <UPageCard v-else-if="method === 'script'" title="Mettre à jour" icon="i-lucide-server" variant="subtle" data-testid="update-script">
          <p class="text-sm text-muted">
            Installe {{ latest ? `la version ${latest.version}` : 'le dernier tag vX.Y.Z' }} avec <code>{{ update.methods.script.script }}</code>, lancé par la tâche planifiée : code, dépendances, interface, migrations, puis redémarrage des services.
          </p>
          <UAlert
            v-if="!update.methods.script.installed"
            color="error"
            variant="subtle"
            icon="i-lucide-file-x"
            title="Script introuvable"
            :description="`${update.methods.script.script} n’existe pas ou n’est pas exécutable (UPDATE_SCRIPT). Cette méthode suppose une installation par git clone.`"
          />
          <UAlert
            v-if="!update.methods.script.schedulerAlive"
            color="warning"
            variant="subtle"
            icon="i-lucide-clock-alert"
            :title="update.methods.script.heartbeatAt ? `La tâche planifiée ne tourne plus (dernier passage ${timeAgo(update.methods.script.heartbeatAt)})` : 'La tâche planifiée n’a jamais tourné'"
            data-testid="scheduler-warning"
          >
            <template #description>
              <p>Ajoutez-la à la crontab de l’utilisateur propriétaire des fichiers (crontab -e) :</p>
              <pre class="mt-2 overflow-x-auto rounded-md bg-elevated p-2 text-xs"><code>{{ cronLine }}</code></pre>
            </template>
          </UAlert>
          <p v-else class="text-xs text-muted">
            Tâche planifiée active, dernier passage {{ timeAgo(update.methods.script.heartbeatAt) }}.
          </p>

          <div v-if="run && run.method === 'script' && run.log !== undefined && run.status !== 'cancelled'" class="flex flex-col gap-2" data-testid="update-run">
            <div class="flex items-center gap-2 text-sm">
              <UBadge :label="RUN_STATUS[run.status].label" :color="RUN_STATUS[run.status].color" variant="subtle" />
              <span class="text-muted">{{ run.fromVersion }} → {{ run.target ?? 'dernière version' }}</span>
              <UButton v-if="run.status === 'requested'" label="Annuler" size="xs" color="neutral" variant="link" data-testid="cancel-update" @click="cancel" />
            </div>
            <pre v-if="run.log" ref="logBox" class="max-h-80 overflow-auto rounded-md bg-elevated p-3 text-xs" data-testid="update-log">{{ run.log }}</pre>
            <p v-else-if="run.status === 'requested'" class="text-sm text-muted">
              La tâche planifiée démarre la mise à jour dans la minute.
            </p>
          </div>
        </UPageCard>

        <!-- Manual -->
        <UPageCard v-else title="Mettre à jour" :description="`Sur le serveur, dans le dossier de ${appName} :`" icon="i-lucide-terminal" variant="subtle" data-testid="manual-update">
          <p class="text-sm font-medium">
            Avec Docker
          </p>
          <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-sm"><code>docker compose pull
docker compose up -d</code></pre>
          <p class="text-sm font-medium">
            Sans Docker (installation par git clone)
          </p>
          <pre class="overflow-x-auto rounded-md bg-elevated p-3 text-sm"><code>TARGET_VERSION={{ latest?.tag ?? 'v0.7.0' }} deploy/update.sh</code></pre>
        </UPageCard>

        <div v-if="method !== 'manual'">
          <UButton
            label="Mettre à jour"
            icon="i-lucide-download"
            size="lg"
            :color="update.updateAvailable ? 'primary' : 'neutral'"
            :variant="update.updateAvailable ? 'solid' : 'outline'"
            :loading="running"
            :disabled="!canStart"
            data-testid="start-update"
            @click="confirmOpen = true"
          />
        </div>

        <UPageCard v-if="update.history.length" title="Historique" variant="subtle">
          <ul class="divide-y divide-default text-sm" data-testid="update-history">
            <li v-for="item in update.history" :key="item.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
              <UBadge :label="RUN_STATUS[item.status].label" :color="RUN_STATUS[item.status].color" variant="subtle" size="sm" />
              <span>{{ item.fromVersion }} → {{ item.target ?? 'dernière version' }}</span>
              <span class="text-muted">{{ METHOD_LABELS[item.method] }}</span>
              <span class="ms-auto text-xs text-muted">{{ formatDate(item.requestedAt) }}{{ item.requestedBy ? ` · ${item.requestedBy}` : '' }}</span>
            </li>
          </ul>
        </UPageCard>
      </div>

      <UModal
        v-model:open="confirmOpen"
        :title="`Mettre à jour ${appName} ?`"
        :description="latest && update?.updateAvailable ? `Installation de la version ${latest.version}.` : 'Installation de la dernière version publiée.'"
      >
        <template #body>
          <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
            <li>L’API, le worker et l’interface redémarrent : {{ appName }} est indisponible une à deux minutes.</li>
            <li>Les emails en attente partent après le redémarrage ; rien n’est perdu.</li>
            <li>La base de données est migrée automatiquement.</li>
            <li v-if="method === 'script'">
              En cas d’échec avant les migrations, le script remet la version actuelle.
            </li>
          </ul>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="confirmOpen = false" />
            <UButton label="Mettre à jour" icon="i-lucide-download" data-testid="confirm-update" @click="start" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
