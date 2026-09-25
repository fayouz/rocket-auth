/**
 * Label of a build version given by `git describe --tags`, like the API does:
 * "v0.7.0" → "0.7.0", "v0.7.0-3-gabc1234" → "0.7.0+3 (abc1234)", anything else as is ("dev", a branch).
 */
export function formatVersion(raw: string | null | undefined): string {
  const value = (raw ?? '').trim()
  const described = value.match(/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)-(\d+)-g([0-9a-f]{4,40})$/)
  if (described) return `${described[1]}+${described[2]} (${described[3]})`
  const release = value.match(/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/)
  if (release) return release[1]!
  return value || 'dev'
}
