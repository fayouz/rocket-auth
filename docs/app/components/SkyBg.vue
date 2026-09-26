<script setup lang="ts">
// Twinkling stars, from the Nuxt UI changelog template (nuxt-ui-templates/changelog, MIT).
const props = withDefaults(defineProps<{
  starCount?: number
  color?: string
  size?: { min: number, max: number }
}>(), {
  starCount: 50,
  color: 'var(--ui-primary)',
  size: () => ({ min: 1, max: 3 }),
})

// Generated once on the server and reused on hydration, so the sky does not jump.
const stars = useState('changelog-sky', () => Array.from({ length: props.starCount }, (_, i) => ({
  id: i,
  x: Math.floor(Math.random() * 100),
  y: Math.floor(Math.random() * 100),
  size: Math.random() * (props.size.max - props.size.min) + props.size.min,
  delay: Math.random() * 5,
})))
</script>

<template>
  <div class="pointer-events-none absolute inset-0 z-[-1] overflow-hidden" aria-hidden="true">
    <div
      v-for="star in stars"
      :key="star.id"
      class="star absolute"
      :style="{
        'left': `${star.x}%`,
        'top': `${star.y}%`,
        'transform': 'translate(-50%, -50%)',
        '--star-size': `${star.size}px`,
        '--star-color': color,
        '--twinkle-delay': `${star.delay}s`,
      }"
    />
  </div>
</template>

<style scoped>
.star {
  width: var(--star-size);
  height: var(--star-size);
  background-color: var(--star-color);
  border-radius: 50%;
  animation: twinkle 2s ease-in-out infinite;
  animation-delay: var(--twinkle-delay);
  will-change: opacity;
}

@keyframes twinkle {
  0%, 100% { opacity: 0.2; }
  50% { opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .star { animation: none; opacity: 0.6; }
}
</style>
