<script setup lang="ts">
import type { ContentNavigationItem } from '@nuxt/content'

const navigation = inject<Ref<ContentNavigationItem[]>>('navigation')
const { site } = useAppConfig()
</script>

<template>
  <UHeader :ui="{ center: 'flex-1' }" to="/" :title="site.title">
    <template #title>
      <UIcon name="i-lucide-rocket" class="size-6 text-primary" />
      <span class="font-bold">Rocket Auth</span>
      <UBadge label="Docs" variant="subtle" size="sm" />
    </template>

    <UContentSearchButton :collapsed="false" label="Rechercher…" class="w-full max-w-sm" />

    <template #right>
      <UButton
        to="/changelog"
        label="Changelog"
        icon="i-lucide-history"
        color="neutral"
        variant="ghost"
        class="hidden sm:inline-flex"
      />
      <UContentSearchButton class="lg:hidden" />
      <UColorModeButton />
      <UButton
        :to="site.repository"
        target="_blank"
        icon="i-simple-icons-github"
        color="neutral"
        variant="ghost"
        aria-label="GitHub"
      />
    </template>

    <template #body>
      <UContentNavigation :navigation="navigation" highlight />
      <UButton to="/changelog" label="Changelog" icon="i-lucide-history" color="neutral" variant="ghost" class="mt-4 sm:hidden" />
    </template>
  </UHeader>
</template>
