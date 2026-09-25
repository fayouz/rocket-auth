<script setup lang="ts">
// The documentation is a separate site: /docs and /docs/<page> here are shortcuts to it.
definePageMeta({ layout: false })

const { docsUrl } = useRuntimeConfig().public
const slug = ([] as string[]).concat(useRoute().params.slug ?? []).filter(Boolean).join('/')
// Pages only map onto the documentation site itself, not onto its sources on GitHub.
const target = slug && !docsUrl.includes('github.com') ? `${docsUrl.replace(/\/$/, '')}/${slug}` : docsUrl
await navigateTo(target, { external: true, replace: true })
</script>

<template>
  <div />
</template>
