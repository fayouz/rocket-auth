<script setup lang="ts">
const { site } = useAppConfig()

const { data: page } = await useAsyncData('changelog', () => queryCollection('changelog').first())
if (!page.value) {
  throw createError({ statusCode: 404, statusMessage: 'Page introuvable', fatal: true })
}

const versions = computed(() => parseChangelog(page.value!.body as unknown as Parameters<typeof parseChangelog>[0]))

useSeoMeta({
  title: 'Changelog',
  description: 'Les nouveautés de chaque version de Rocket Auth.',
})
</script>

<template>
  <div class="xl:grid xl:grid-cols-2">
    <UPageSection
      title="Nouveautés"
      description="Les évolutions de chaque version de Rocket Auth : nouvelles fonctionnalités, changements, corrections et sécurité."
      orientation="vertical"
      :links="[
        { label: 'Documentation', icon: 'i-lucide-book-open', variant: 'ghost', size: 'md', to: '/getting-started/introduction' },
        { label: 'CHANGELOG.md', icon: 'i-simple-icons-github', variant: 'ghost', size: 'md', to: `${site.repository}/blob/develop/CHANGELOG.md`, target: '_blank' },
      ]"
      :ui="{
        root: 'relative isolate overflow-hidden border-b border-default xl:sticky xl:top-(--ui-header-height) xl:h-[calc(100vh-var(--ui-header-height))] xl:border-b-0',
        container: 'h-full items-center justify-center',
        wrapper: 'flex flex-col',
        headline: 'mb-6',
        title: 'text-left text-4xl',
        description: 'max-w-lg text-left',
        links: '-ms-2.5 justify-start gap-1',
      }"
    >
      <template #top>
        <SkyBg />
        <div class="absolute top-1/2 -right-1/2 z-[-1] size-60 -translate-y-1/2 rounded-full bg-primary blur-[300px] sm:size-100" />
      </template>

      <template #headline>
        <div class="flex items-center gap-2 font-bold text-highlighted">
          <UIcon name="i-lucide-rocket" class="size-6 text-primary" />
          Rocket Auth
          <UBadge label="Changelog" variant="subtle" size="sm" />
        </div>
      </template>

      <template #default />
    </UPageSection>

    <section class="px-4 sm:px-6 xl:-ms-30 xl:flex-1 xl:px-0">
      <UChangelogVersions
        as="main"
        :indicator-motion="false"
        :ui="{ root: 'py-16 sm:py-24 lg:py-32', indicator: 'inset-y-0' }"
      >
        <UChangelogVersion
          v-for="(entry, index) in versions"
          :id="entry.slug"
          :key="entry.slug"
          :title="entry.title"
          :description="entry.description"
          :date="entry.date"
          :badge="index === versions.findIndex(v => v.date) ? { label: 'Dernière version', color: 'primary', variant: 'subtle' } : undefined"
          :ui="{
            root: 'flex items-start',
            container: 'max-w-xl min-w-0',
            header: 'border-b border-default pb-4',
            title: 'text-3xl',
            date: 'font-mono text-xs/9 text-highlighted',
            indicator: 'sticky top-(--ui-header-height) -mt-16 pt-16 sm:-mt-24 sm:pt-24 lg:-mt-32 lg:pt-32',
          }"
        >
          <template #body>
            <ContentRenderer :value="{ body: entry.body }" />
          </template>
        </UChangelogVersion>
      </UChangelogVersions>
    </section>
  </div>
</template>
