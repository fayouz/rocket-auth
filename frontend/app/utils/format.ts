export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} o`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} Ko`
  if (bytes < 1024 ** 3) return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} Mo`
  return `${(bytes / 1024 ** 3).toFixed(1).replace('.', ',')} Go`
}

const relative = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' })
const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 365 * 24 * 3600],
  ['month', 30 * 24 * 3600],
  ['week', 7 * 24 * 3600],
  ['day', 24 * 3600],
  ['hour', 3600],
  ['minute', 60],
]

/** "il y a 5 minutes", "hier"… */
export function timeAgo(value: string | Date | null | undefined, now: Date = new Date()): string {
  if (!value) return 'jamais'
  const seconds = Math.round((new Date(value).getTime() - now.getTime()) / 1000)
  for (const [unit, size] of RELATIVE_UNITS) {
    if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit)
  }
  return 'à l’instant'
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(value)
}

export function formatPercent(value: number | null | undefined, digits = 1): string {
  return value === null || value === undefined
    ? '—'
    : `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: digits }).format(value)} %`
}

export function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function isEmail(value: string): boolean {
  return EMAIL_PATTERN.test(value.trim())
}

export function formatDate(value: string | null | undefined): string {
  return value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—'
}
