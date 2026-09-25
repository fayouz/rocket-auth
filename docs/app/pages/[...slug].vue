<script setup lang="ts">
definePageMeta({ layout: 'docs' })

const route = useRoute()
// Static hosts redirect /page to /page/: content paths never end with a slash.
const path = computed(() => route.path.replace(/\/+$/, '') || '/')

const { data: page } = await useAsyncData(path.value, () => queryCollection('docs').path(path.value).first())
if (!page.value) {
  throw createError({ statusCode: 404, statusMessage: 'Page introuvable', fatal: true })
}

const { data: surround } = await useAsyncData(`${path.value}-surround`, () =>
  queryCollectionItemSurroundings('docs', path.value, { fields: ['description'] }),
)

useSeoMeta({
  title: page.value.title,
  description: page.value.description,
})
</script>

<template>
  <UPage v-if="page">
    <UPageHeader :title="page.title" :description="page.description" :links="page.links" />

    <UPageBody>
      <ContentRenderer :value="page" />

      <USeparator v-if="surround?.length" />
      <UContentSurround :surround="surround" />
    </UPageBody>

    <template v-if="page.body?.toc?.links?.length" #right>
      <UContentToc title="Sur cette page" :links="page.body.toc.links" highlight />
    </template>
  </UPage>
</template>
