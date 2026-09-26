// Minimark: the compact AST Nuxt Content stores for Markdown, e.g. ['h2', { id }, 'text', ['span', {}, '0.6.0']].
type MinimarkNode = string | [string, Record<string, unknown>, ...MinimarkNode[]]

export interface ChangelogVersion {
  version: string
  /** "v1.2.0" for a release, the heading as is otherwise ("Non publié"). */
  title: string
  /** Unique anchor. */
  slug: string
  date: string | undefined
  /** First paragraph under the version heading: its one-line summary. */
  description: string | undefined
  body: { type: 'minimark', value: MinimarkNode[] }
}

function text(node: MinimarkNode): string {
  return typeof node === 'string' ? node : node.slice(2).map(child => text(child as MinimarkNode)).join('')
}

/** Heading ids must stay unique once every version is on the same page ("Ajouté" appears in each). */
function prefixIds(node: MinimarkNode, prefix: string): MinimarkNode {
  if (typeof node === 'string') return node
  const [tag, props, ...children] = node
  return [tag, typeof props.id === 'string' ? { ...props, id: `${prefix}-${props.id}` } : props, ...children.map(c => prefixIds(c, prefix))]
}

/**
 * Splits a Keep a Changelog document ("## [1.2.0] - 2026-09-24" sections) into versions, newest first.
 */
export function parseChangelog(body: { value: MinimarkNode[] }): ChangelogVersion[] {
  const versions: ChangelogVersion[] = []
  let current: ChangelogVersion | undefined

  for (const node of body.value) {
    if (Array.isArray(node) && node[0] === 'h2') {
      const heading = text(node)
      // "[1.2.0] - 2026-09-24", or "[Non publié]" for changes not released yet.
      const [, version = heading, date] = heading.trim().match(/^\[?(.+?)\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?$/) ?? []
      const slug = /^\d/.test(version) ? `v${version}` : version.toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
      current = { version, title: /^\d/.test(version) ? `v${version}` : version, slug, date, description: undefined, body: { type: 'minimark', value: [] } }
      versions.push(current)
      continue
    }
    if (!current) continue
    if (!current.description && !current.body.value.length && Array.isArray(node) && node[0] === 'p') {
      current.description = text(node)
      continue
    }
    current.body.value.push(prefixIds(node, current.slug))
  }

  return versions
}
