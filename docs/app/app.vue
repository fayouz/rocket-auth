<script setup lang="ts">
import { fr } from '@nuxt/ui/locale'

const { data: navigation } = await useAsyncData('navigation', () => queryCollectionNavigation('docs'))
const { data: files } = useLazyAsyncData('search', () => queryCollectionSearchSections('docs'), { server: false })

provide('navigation', navigation)

useHead({
  titleTemplate: title => (title ? `${title} · Rocket Auth` : 'Rocket Auth — Documentation'),
  link: [{ rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' }],
})
</script>

<template>
  <UApp :locale="fr">
    <NuxtLoadingIndicator color="var(--ui-primary)" />
    <AppHeader />

    <UMain>
      <NuxtLayout>
        <NuxtPage />
      </NuxtLayout>
    </UMain>

    <AppFooter />

    <ClientOnly>
      <LazyUContentSearch
        :files="files"
        :navigation="navigation"
        :fuse="{ resultLimit: 42 }"
        placeholder="Rechercher dans la documentation…"
      />
    </ClientOnly>
  </UApp>
</template>
