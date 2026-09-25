<script setup lang="ts">
defineProps<{
  title: string
  value: string
  icon: string
  /** Tailwind classes for the icon badge, e.g. "bg-primary/10 text-primary". */
  tone: string
}>()
</script>

<template>
  <UCard :ui="{ body: 'flex h-full flex-col gap-3 p-4 sm:p-5' }" class="h-full">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm text-muted">
          {{ title }}
        </p>
        <p class="mt-1 text-3xl font-semibold tabular-nums text-highlighted">
          {{ value }}
        </p>
      </div>
      <div class="flex size-10 shrink-0 items-center justify-center rounded-lg" :class="tone">
        <UIcon :name="icon" class="size-5" />
      </div>
    </div>
    <slot />
    <div v-if="$slots.footer" class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
      <slot name="footer" />
    </div>
  </UCard>
</template>
