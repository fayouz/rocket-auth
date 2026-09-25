<script setup lang="ts">
import type { LdapConfig, LdapTestResult } from '~/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Annuaire LDAP · ${appName}` })

const api = useApi()
const toast = useToast()

const { data: config } = await useAsyncData('ldap-config', () => api<LdapConfig>('/api/ldap/config'))

function toForm(c: LdapConfig | null | undefined) {
  return {
    enabled: c?.enabled ?? false,
    url: c?.url ?? 'ldap://',
    startTls: c?.startTls ?? false,
    baseDn: c?.baseDn ?? '',
    bindDn: c?.bindDn ?? '',
    bindPassword: '',
    userFilter: c?.userFilter ?? '(objectClass=inetOrgPerson)',
    adminGroupDn: c?.adminGroupDn ?? '',
    attributes: { ...(c?.attributes ?? { email: 'mail', firstName: 'givenName', lastName: 'sn', groups: 'memberOf' }) },
  }
}

const form = reactive(toForm(config.value))
watch(config, value => Object.assign(form, toForm(value)))

const saving = ref(false)
const testing = ref(false)
const syncing = ref(false)
const testResult = ref<LdapTestResult | null>(null)
const resetOpen = ref(false)

const body = () => ({ ...form, bindPassword: form.bindPassword || null })

async function save() {
  saving.value = true
  try {
    config.value = await api<LdapConfig>('/api/ldap/config', { method: 'PUT', body: body() })
    toast.add({ title: 'Configuration LDAP enregistrée', color: 'success', icon: 'i-lucide-check' })
  }
  catch (error) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await api<LdapTestResult>('/api/ldap/test', { method: 'POST', body: body() })
  }
  catch (error) {
    toast.add({ title: 'Test impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    testing.value = false
  }
}

async function reset() {
  try {
    config.value = await api<LdapConfig>('/api/ldap/config', { method: 'DELETE' })
    resetOpen.value = false
    testResult.value = null
    toast.add({ title: 'Valeurs par défaut du .env rétablies', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Impossible de revenir aux valeurs par défaut', description: apiErrorMessage(error), color: 'error' })
  }
}

async function sync(dryRun: boolean) {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number, conflicts: string[] }>('/api/ldap/sync', { method: 'POST', query: { dryRun } })
    toast.add({
      title: dryRun ? 'Simulation de synchronisation' : 'Synchronisation terminée',
      description: `${report.created} créé(s), ${report.updated} mis à jour, ${report.disabled} désactivé(s)${report.conflicts.length ? `, ${report.conflicts.length} conflit(s)` : ''}`,
      color: 'success',
    })
  }
  catch (error) {
    toast.add({ title: 'Synchronisation impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}

const dirty = computed(() => JSON.stringify({ ...form, bindPassword: '' }) !== JSON.stringify({ ...toForm(config.value), bindPassword: '' }) || form.bindPassword !== '')
</script>

<template>
  <UDashboardPanel id="ldap">
    <template #header>
      <UDashboardNavbar title="Annuaire LDAP">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-flask-conical" label="Simuler la synchro" color="neutral" variant="ghost" :loading="syncing" :disabled="!config?.enabled || dirty" @click="sync(true)" />
          <UButton icon="i-lucide-refresh-cw" label="Synchroniser" color="neutral" variant="outline" :loading="syncing" :disabled="!config?.enabled || dirty" @click="sync(false)" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <form class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-testid="ldap-form" @submit.prevent="save">
        <UAlert
          v-if="config?.source === 'environment'"
          color="info"
          variant="subtle"
          icon="i-lucide-file-cog"
          title="Configuration par défaut (.env)"
          description="Ces valeurs viennent des variables LDAP_* du serveur. Une fois enregistrée ici, la configuration est conservée en base (mot de passe chiffré) et prend le dessus."
          data-testid="ldap-source"
        />

        <UPageCard title="Connexion" description="Le compte de service sert à lire l’annuaire lors des synchronisations. Les utilisateurs se connectent ensuite avec leur propre mot de passe LDAP.">
          <USwitch v-model="form.enabled" label="Activer l’authentification et la synchronisation LDAP" />
          <div class="grid gap-3 sm:grid-cols-3">
            <UFormField label="URL du serveur" required hint="ldap:// ou ldaps://" class="sm:col-span-2">
              <UInput v-model="form.url" placeholder="ldaps://annuaire.exemple.com:636" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Chiffrement" help="STARTTLS sur ldap:// uniquement">
              <USwitch v-model="form.startTls" label="STARTTLS" :disabled="form.url.startsWith('ldaps://')" />
            </UFormField>
            <UFormField label="Base de recherche" required class="sm:col-span-3">
              <UInput v-model="form.baseDn" placeholder="ou=people,dc=exemple,dc=com" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Compte de service (DN)" class="sm:col-span-2">
              <UInput v-model="form.bindDn" placeholder="cn=lecteur,dc=exemple,dc=com" class="w-full font-mono" autocomplete="off" />
            </UFormField>
            <UFormField label="Mot de passe" :hint="config?.hasBindPassword ? 'Vide : inchangé' : undefined">
              <UInput v-model="form.bindPassword" type="password" autocomplete="new-password" class="w-full" />
            </UFormField>
          </div>
        </UPageCard>

        <UPageCard title="Utilisateurs" description="Quelles entrées deviennent des comptes, et comment lire leurs informations.">
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField label="Filtre" class="sm:col-span-2" help="Ex. Active Directory : (&(objectClass=user)(memberOf=cn=mail-users,ou=groups,dc=exemple,dc=com))">
              <UInput v-model="form.userFilter" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Groupe des administrateurs (DN)" class="sm:col-span-2" help="Ses membres reçoivent le rôle administrateur. Vide : les administrateurs sont gérés dans l’application.">
              <UInput v-model="form.adminGroupDn" placeholder="cn=mailer-admins,ou=groups,dc=exemple,dc=com" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Attribut email">
              <UInput v-model="form.attributes.email" :placeholder="config?.defaults.attributes.email" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Attribut groupes">
              <UInput v-model="form.attributes.groups" :placeholder="config?.defaults.attributes.groups" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Attribut prénom">
              <UInput v-model="form.attributes.firstName" :placeholder="config?.defaults.attributes.firstName" class="w-full font-mono" />
            </UFormField>
            <UFormField label="Attribut nom">
              <UInput v-model="form.attributes.lastName" :placeholder="config?.defaults.attributes.lastName" class="w-full font-mono" />
            </UFormField>
          </div>
        </UPageCard>

        <div class="flex flex-wrap items-center gap-2">
          <UButton type="submit" label="Enregistrer" icon="i-lucide-save" :loading="saving" />
          <UButton label="Tester" icon="i-lucide-plug-zap" color="neutral" variant="outline" :loading="testing" data-testid="ldap-test" @click="test" />
          <UButton
            v-if="config?.source === 'database'"
            label="Revenir aux valeurs par défaut (.env)"
            icon="i-lucide-rotate-ccw"
            color="neutral"
            variant="ghost"
            class="ms-auto"
            @click="resetOpen = true"
          />
        </div>

        <UAlert
          v-if="testResult"
          :color="testResult.ok ? 'success' : 'error'"
          variant="subtle"
          :icon="testResult.ok ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
          :title="testResult.ok ? 'Connexion réussie' : 'Échec de la connexion'"
          :description="testResult.message"
          data-testid="ldap-test-result"
        />
        <UCard v-if="testResult?.sample.length" :ui="{ body: 'p-0 sm:p-0' }">
          <template #header>
            <p class="text-sm font-medium">
              Aperçu (tests seulement, rien n’est enregistré)
            </p>
          </template>
          <ul class="divide-y divide-default text-sm">
            <li v-for="user in testResult.sample" :key="user.dn" class="flex items-center gap-3 px-4 py-2">
              <div class="min-w-0 flex-1">
                <p class="font-medium">
                  {{ [user.firstName, user.lastName].filter(Boolean).join(' ') || user.email }}
                  <UBadge v-if="user.admin" label="Administrateur" color="warning" variant="subtle" size="sm" class="ms-1" />
                </p>
                <p class="truncate text-xs text-muted">
                  {{ user.email }} · <span class="font-mono">{{ user.dn }}</span>
                </p>
              </div>
            </li>
          </ul>
        </UCard>
      </form>

      <UModal v-model:open="resetOpen" title="Revenir aux valeurs par défaut ?" description="La configuration enregistrée est supprimée : les variables LDAP_* du .env s’appliquent de nouveau.">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="resetOpen = false" />
            <UButton label="Revenir aux valeurs du .env" color="warning" @click="reset" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
