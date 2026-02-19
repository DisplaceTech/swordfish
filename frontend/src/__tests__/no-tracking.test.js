import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { resolve, join } from 'node:path'

const ROOT = resolve(import.meta.dirname, '../../..')

// Patterns that indicate cookies, analytics, or tracking
const TRACKING_PATTERNS = [
  /document\.cookie/,
  /navigator\.sendBeacon/,
  /\bgtag\s*\(/,
  /\bga\s*\(\s*['"](?:send|event)/,
  /_gaq\.push/,
  /\bfbq\s*\(/,
  /\bpixel\b.*track/i,
  /mixpanel\.(track|identify)/,
  /analytics\.(track|identify|page)/,
  /amplitude\.(getInstance|logEvent)/,
  /hotjar|hj\s*\(/,
  /clarity\s*\(/,
  /plausible\s*\(/,
  /matomo|piwik/i,
  /intercom\s*\(/,
  /\bLogrocket\b/i,
]

// External hostnames that must not appear as fetch/script/img targets
const FORBIDDEN_EXTERNAL_HOSTS = [
  'google-analytics.com',
  'googletagmanager.com',
  'facebook.net',
  'facebook.com/tr',
  'hotjar.com',
  'clarity.ms',
  'mixpanel.com',
  'segment.io',
  'amplitude.com',
  'analytics.js',
  'cdn.logrocket.io',
]

function readSourceFiles() {
  const srcDir = join(ROOT, 'frontend/src')
  const files = []
  function walk(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name)
      if (entry.isDirectory() && entry.name !== '__tests__') {
        walk(full)
      } else if (entry.isFile() && /\.(js|jsx|ts|tsx)$/.test(entry.name)) {
        files.push({ path: full, content: readFileSync(full, 'utf8') })
      }
    }
  }
  walk(srcDir)
  return files
}

function readBuiltFiles() {
  const distDir = join(ROOT, 'server/static/dist/assets')
  const files = []
  for (const entry of readdirSync(distDir, { withFileTypes: true })) {
    if (entry.isFile() && entry.name.endsWith('.js')) {
      const full = join(distDir, entry.name)
      files.push({ path: full, content: readFileSync(full, 'utf8') })
    }
  }
  return files
}

describe('DIS-917 — No tracking, cookies, or analytics in SPA', () => {
  describe('source files', () => {
    const sourceFiles = readSourceFiles()

    it('contains no cookie-setting or tracking API calls', () => {
      for (const { path, content } of sourceFiles) {
        for (const pattern of TRACKING_PATTERNS) {
          expect(
            pattern.test(content),
            `Tracking pattern ${pattern} found in ${path}`
          ).toBe(false)
        }
      }
    })

    it('contains no localStorage or sessionStorage usage', () => {
      for (const { path, content } of sourceFiles) {
        expect(
          /localStorage\s*\.\s*(setItem|getItem|removeItem|clear)/.test(content),
          `localStorage usage found in ${path}`
        ).toBe(false)
        expect(
          /sessionStorage\s*\.\s*(setItem|getItem|removeItem|clear)/.test(content),
          `sessionStorage usage found in ${path}`
        ).toBe(false)
      }
    })

    it('makes no requests to forbidden external hosts', () => {
      for (const { path, content } of sourceFiles) {
        for (const host of FORBIDDEN_EXTERNAL_HOSTS) {
          expect(
            content.includes(host),
            `Forbidden external host "${host}" found in ${path}`
          ).toBe(false)
        }
      }
    })

    it('all fetch() calls target same-origin paths only', () => {
      for (const { path, content } of sourceFiles) {
        // Match fetch('...') or fetch("...") calls
        const fetchCalls = [...content.matchAll(/fetch\s*\(\s*['"`]([^'"`]+)['"`]/g)]
        for (const [, url] of fetchCalls) {
          expect(
            url.startsWith('/') || url.startsWith('./') || url.startsWith('../'),
            `fetch() call with non-relative URL "${url}" found in ${path}`
          ).toBe(true)
        }
      }
    })
  })

  describe('built output', () => {
    const builtFiles = readBuiltFiles()

    it('built JS contains no tracking patterns', () => {
      for (const { path, content } of builtFiles) {
        for (const pattern of TRACKING_PATTERNS) {
          expect(
            pattern.test(content),
            `Tracking pattern ${pattern} found in built file ${path}`
          ).toBe(false)
        }
      }
    })

    it('built JS references no forbidden external hosts', () => {
      for (const { path, content } of builtFiles) {
        for (const host of FORBIDDEN_EXTERNAL_HOSTS) {
          expect(
            content.includes(host),
            `Forbidden external host "${host}" found in built file ${path}`
          ).toBe(false)
        }
      }
    })

    it('built HTML loads no external scripts or stylesheets', () => {
      const htmlPath = join(ROOT, 'server/static/dist/index.html')
      const html = readFileSync(htmlPath, 'utf8')

      // No <script src="http..."> or <link href="http...">
      const externalScripts = [...html.matchAll(/<script[^>]+src=["']https?:\/\//gi)]
      expect(externalScripts).toHaveLength(0)

      const externalStyles = [...html.matchAll(/<link[^>]+href=["']https?:\/\//gi)]
      expect(externalStyles).toHaveLength(0)
    })

    it('built HTML contains no inline tracking pixels', () => {
      const htmlPath = join(ROOT, 'server/static/dist/index.html')
      const html = readFileSync(htmlPath, 'utf8')

      // No <img> tags with external tracking URLs
      const imgTags = [...html.matchAll(/<img[^>]+src=["']https?:\/\/([^"']+)/gi)]
      for (const [, host] of imgTags) {
        for (const forbidden of FORBIDDEN_EXTERNAL_HOSTS) {
          expect(
            host.includes(forbidden),
            `Tracking pixel to "${host}" found in built HTML`
          ).toBe(false)
        }
      }
    })
  })
})
