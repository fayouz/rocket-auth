<script setup lang="ts">
const { data: page } = await useAsyncData('landing', () => queryCollection('landing').first())
if (!page.value) {
  throw createError({ statusCode: 404, statusMessage: 'Page introuvable', fatal: true })
}

useSeoMeta({
  title: page.value.title,
  description: page.value.description,
})
</script>

<template>
  <ContentRenderer v-if="page" :value="page" />
</template>
