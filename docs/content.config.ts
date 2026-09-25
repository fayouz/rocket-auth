import { fileURLToPath } from 'node:url'
import { defineCollection, defineContentConfig, z } from '@nuxt/content'

export default defineContentConfig({
  collections: {
    landing: defineCollection({
      type: 'page',
      source: 'index.md',
    }),
    // Copy of the repository's CHANGELOG.md, made by nuxt.config.ts: the single source of /changelog.
    changelog: defineCollection({
      type: 'page',
      source: { cwd: fileURLToPath(new URL('.changelog', import.meta.url)), include: 'CHANGELOG.md', prefix: '/changelog' },
    }),
    docs: defineCollection({
      type: 'page',
      source: { include: '**', exclude: ['index.md'] },
      schema: z.object({
        links: z.array(z.object({
          label: z.string(),
          icon: z.string(),
          to: z.string(),
          target: z.string().optional(),
        })).optional(),
      }),
    }),
  },
})
