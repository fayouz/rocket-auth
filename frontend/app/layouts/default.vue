<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const auth = useAuth()
const app = useAppConfig().rocket
const { version, updateAvailable, load: loadVersion } = useAppVersion()

watch(() => auth.me.value, (me) => {
  if (me) loadVersion()
}, { immediate: true })

const items = computed<NavigationMenuItem[][]>(() => [
  [
    { label: 'Tableau de bord', icon: 'i-lucide-layout-dashboard', to: '/' },
  ],
  app.navigation.filter(item => !item.admin || auth.isAdmin.value) as NavigationMenuItem[],
  auth.isAdmin.value
    ? [
        { label: 'Administration', type: 'label' },
        ...app.adminNavigation,
        { label: 'Utilisateurs', icon: 'i-lucide-users', to: '/users' },
        { label: 'Serveurs d’authentification', icon: 'i-lucide-shield-check', to: '/authentication-servers' },
        { label: 'Applications', icon: 'i-lucide-key-round', to: '/applications' },
        { label: 'Mises à jour', icon: updateAvailable.value ? 'i-lucide-circle-arrow-up' : 'i-lucide-refresh-cw', to: '/updates' },
      ]
    : [],
])
</script>

<template>
  <UDashboardGroup>
    <UDashboardSidebar collapsible resizable>
      <template #header="{ collapsed }">
        <div class="flex items-center gap-2 font-semibold">
          <UIcon :name="app.icon" class="size-5 text-primary" />
          <span v-if="!collapsed">{{ app.name }}</span>
        </div>
      </template>

      <template #default="{ collapsed }">
        <template v-for="(group, index) in items" :key="index">
          <UNavigationMenu v-if="group.length" :items="group" :collapsed="collapsed" orientation="vertical" />
        </template>
      </template>

      <template #footer="{ collapsed }">
        <div class="flex w-full flex-col gap-2">
          <template v-if="!collapsed">
            <NuxtLink v-if="auth.isAdmin.value" to="/updates" class="flex items-center gap-2 px-2 text-xs text-muted hover:text-default" data-testid="app-version">
              <span class="truncate">Version {{ version }}</span>
              <UBadge v-if="updateAvailable" label="Nouveau" size="sm" variant="subtle" icon="i-lucide-circle-arrow-up" class="shrink-0" />
            </NuxtLink>
            <p v-else class="px-2 text-xs text-muted" data-testid="app-version">
              Version {{ version }}
            </p>
          </template>
          <div class="flex w-full items-center gap-2">
            <UUser
              v-if="auth.me.value?.user && !collapsed"
              :name="auth.me.value.user.displayName"
              :description="auth.me.value.user.email"
              size="sm"
              class="min-w-0 flex-1"
            />
            <UButton
              icon="i-lucide-log-out"
              color="neutral"
              variant="ghost"
              aria-label="Se déconnecter"
              @click="auth.logout()"
            />
          </div>
        </div>
      </template>
    </UDashboardSidebar>

    <slot />
  </UDashboardGroup>
</template>
