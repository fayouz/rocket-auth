<script setup lang="ts">
const quotes = useAppConfig().rocket.quotes

// One quote per day, stable across refreshes.
const [quote, author] = quotes[Math.floor(Date.now() / 86_400_000) % quotes.length] ?? ['', '']
</script>

<template>
  <div class="relative h-full min-h-40 overflow-hidden rounded-xl border border-default bg-gradient-to-b from-orange-50 to-amber-100/70 dark:from-zinc-900 dark:to-orange-950/40">
    <svg viewBox="0 0 600 200" preserveAspectRatio="xMidYMax slice" class="absolute inset-0 size-full" aria-hidden="true">
      <circle cx="505" cy="52" r="26" class="fill-amber-300/80 dark:fill-amber-200/30" />
      <!-- A rocket and its trail -->
      <path d="M150 70 C 230 20, 300 90, 380 45" fill="none" stroke-dasharray="4 6" stroke-width="2" class="stroke-primary/40" />
      <path d="M380 45 l 26 -8 l -12 22 l -4 -9 z" class="fill-primary" />
      <path d="M394 59 l -4 -9 l 16 -13 z" class="fill-primary/70" />
      <!-- Hills, back to front -->
      <path d="M0 150 C 90 105, 170 120, 260 140 S 430 95, 600 125 V200 H0 Z" class="fill-orange-200/80 dark:fill-orange-900/40" />
      <path d="M0 170 C 120 130, 220 150, 330 165 S 500 135, 600 150 V200 H0 Z" class="fill-orange-300/70 dark:fill-orange-800/40" />
      <path d="M0 188 C 150 160, 300 185, 430 175 S 560 170, 600 178 V200 H0 Z" class="fill-orange-400/60 dark:fill-orange-700/40" />
      <!-- Pines -->
      <g class="fill-emerald-700/70 dark:fill-emerald-500/40">
        <path d="M455 170 l 12 -38 l 12 38 z" />
        <path d="M478 172 l 9 -28 l 9 28 z" />
        <path d="M530 166 l 14 -44 l 14 44 z" />
        <path d="M60 176 l 10 -30 l 10 30 z" />
        <path d="M80 178 l 7 -22 l 7 22 z" />
      </g>
    </svg>
    <figure class="relative flex h-full max-w-[65%] flex-col justify-center gap-1 p-5">
      <blockquote class="text-sm font-medium italic text-highlighted sm:text-base">
        « {{ quote }} »
      </blockquote>
      <figcaption class="text-xs text-muted">
        — {{ author }}
      </figcaption>
    </figure>
  </div>
</template>
