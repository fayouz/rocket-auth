<script setup lang="ts">
const props = withDefaults(defineProps<{
  values: number[]
  /** Tailwind text color class; the line and area use currentColor. */
  color?: string
  height?: number
}>(), { color: 'text-primary', height: 40 })

const WIDTH = 120
const id = useId()

const points = computed(() => {
  const max = Math.max(...props.values, 1)
  const step = props.values.length > 1 ? WIDTH / (props.values.length - 1) : WIDTH
  return props.values.map((v, i) => [i * step, props.height - 2 - (v / max) * (props.height - 4)] as const)
})
const line = computed(() => points.value.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' '))
const area = computed(() => `0,${props.height} ${line.value} ${WIDTH},${props.height}`)
</script>

<template>
  <svg :viewBox="`0 0 ${WIDTH} ${height}`" preserveAspectRatio="none" :class="color" class="w-full" :style="{ height: `${height}px` }" aria-hidden="true">
    <defs>
      <linearGradient :id="id" x1="0" x2="0" y1="0" y2="1">
        <stop offset="0%" stop-color="currentColor" stop-opacity="0.25" />
        <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
      </linearGradient>
    </defs>
    <polygon :points="area" :fill="`url(#${id})`" />
    <polyline :points="line" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>
