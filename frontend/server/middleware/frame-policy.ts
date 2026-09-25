/**
 * Clickjacking protection: the interface can never be framed.
 */
export default defineEventHandler((event) => {
  setResponseHeader(event, 'Content-Security-Policy', "frame-ancestors 'none'")
  setResponseHeader(event, 'X-Frame-Options', 'DENY')
})
