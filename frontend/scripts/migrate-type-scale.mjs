#!/usr/bin/env node
/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — one-off codemod: arbitrary Tailwind font sizes -> the token scale.
 *
 * Scale steps (src/styles/tokens.css, mapped in src/index.css @theme): xs 13, sm 14, base 16, lg 18,
 * xl 20, 2xl 24, 3xl 30, 4xl 36. A value is rewritten ONLY when a step lies within 1px; ties go to
 * the smaller step. Everything else is left alone and is allow-listed by arbitraryTypeSizes.test.ts.
 *
 * Skipped on purpose: tests; features/marketing + features/auth (visual-regression baselines);
 * anything in the transitive import closure of the PDF print route (features/reports/PrintReport.tsx)
 * and any path containing "print" — the PDF fit floor must not move; inline numeric `fontSize:`
 * (chart-library props, SVG attributes cannot take var()); `leading-[…]` (no matching leading token).
 *
 * Usage: node scripts/migrate-type-scale.mjs [--dry]
 */
import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { join, dirname, resolve, relative } from 'node:path'

const ROOT = resolve(import.meta.dirname, '..')
const SRC = join(ROOT, 'src')
const DRY = process.argv.includes('--dry')

export const MAP = {
  '12': 'text-xs', '12.5': 'text-xs', '13': 'text-xs', '13.5': 'text-xs',
  '14': 'text-sm', '14.5': 'text-sm', '15': 'text-sm',
  '16': 'text-base', '17': 'text-lg', '18': 'text-lg',
  '21': 'text-xl', '24': 'text-2xl', '30': 'text-3xl',
}

function walk(dir, out = []) {
  for (const n of readdirSync(dir)) {
    const p = join(dir, n)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (/\.(ts|tsx)$/.test(n)) out.push(p)
  }
  return out
}

function resolveImport(from, spec) {
  if (!spec.startsWith('.')) return null
  const base = resolve(dirname(from), spec)
  for (const c of [base + '.tsx', base + '.ts', join(base, 'index.tsx'), join(base, 'index.ts'), base]) {
    if (existsSync(c) && statSync(c).isFile()) return c
  }
  return null
}

function closure(entry) {
  const seen = new Set()
  const stack = [entry]
  while (stack.length) {
    const f = stack.pop()
    if (seen.has(f) || !existsSync(f)) continue
    seen.add(f)
    const s = readFileSync(f, 'utf8')
    for (const m of s.matchAll(/(?:from|import)\s*\(?\s*['"](\.[^'"]+)['"]/g)) {
      const r = resolveImport(f, m[1])
      if (r) stack.push(r)
    }
  }
  return seen
}

const printSet = closure(join(SRC, 'features/reports/PrintReport.tsx'))
const skip = (f) => {
  const r = relative(ROOT, f)
  return /\.test\.tsx?$/.test(r) || r.startsWith('src/features/marketing') || r.startsWith('src/features/auth') ||
    /print/i.test(r) || printSet.has(f)
}

const RE = /text-\[(\d+(?:\.\d+)?)px\]/g
let files = 0, edits = 0
const tally = {}
for (const f of walk(SRC)) {
  if (skip(f)) continue
  const s = readFileSync(f, 'utf8')
  let n = 0
  const next = s.replace(RE, (m, v) => {
    const to = MAP[v]
    if (!to) return m
    n++
    tally[`${v}px -> ${to}`] = (tally[`${v}px -> ${to}`] ?? 0) + 1
    return to
  })
  if (n) { files++; edits += n; if (!DRY) writeFileSync(f, next) }
}
console.log(`${DRY ? '[dry] ' : ''}files=${files} edits=${edits} printClosure=${printSet.size}`)
for (const [k, v] of Object.entries(tally).sort()) console.log(`  ${k}: ${v}`)
