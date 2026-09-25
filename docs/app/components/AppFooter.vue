<script setup lang="ts">
const { site } = useAppConfig()
</script>

<template>
  <UFooter>
    <template #left>
      <p class="text-sm text-muted">
        Rocket Auth · Documentation
      </p>
    </template>

    <template #right>
      <UButton
        :to="site.repository"
        target="_blank"
        icon="i-simple-icons-github"
        color="neutral"
        variant="ghost"
        aria-label="GitHub"
      />
    </template>
  </UFooter>
</template>
