<script setup lang="ts">
// The changelog lives on the documentation site (or on GitHub): /changelog here is a shortcut to it.
definePageMeta({ layout: false })

const { changelogUrl } = useRuntimeConfig().public
await navigateTo(changelogUrl, { external: true, replace: true })
</script>

<template>
  <div />
</template>
