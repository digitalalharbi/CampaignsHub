import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * EMAIL-CLIENT-COMPAT-001 — every rendered message, in three engines, at 360/390/600px, in three modes:
 *  - normal;
 *  - STRIPPED: every <style> removed — what the Gmail app shows for a non-Google (IMAP) account;
 *  - INVERTED: a colour-inversion filter — a stand-in for Gmail's forced dark mode.
 * It asserts no horizontal overflow, that every block in the content column has the same left/right edges (±1px), that no
 * decision-card button label or WHERE piece wraps, and that the stripped render differs from the normal one by no more than
 * 0.5% of its pixels at 390px. Real Gmail/Outlook/Apple Mail are NOT simulated by this; see docs/email-compat/EM-notes.md.
 */
const DIR = process.env.MAIL_PREVIEW_DIR ?? '';
const files = DIR && fs.existsSync(DIR) ? fs.readdirSync(DIR).filter((f) => f.endsWith('.html') && !f.endsWith('.stripped.html')).sort() : [];
const WIDTHS = [360, 390, 600];

test.skip(files.length === 0, 'set MAIL_PREVIEW_DIR to a directory of rendered messages (php artisan notifications:preview)');

async function open(page: Page, file: string, width: number, mode: 'normal' | 'stripped' | 'inverted'): Promise<void> {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('file://' + path.join(DIR, file));
  if (mode === 'stripped') {
    await page.evaluate(() => document.querySelectorAll('style').forEach((s) => s.remove()));
  }
  if (mode === 'inverted') {
    await page.addStyleTag({ content: 'html { filter: invert(1) hue-rotate(180deg) !important; }' });
  }
}

async function measure(page: Page) {
  return page.evaluate(() => {
    const overflow = document.documentElement.scrollWidth - window.innerWidth;
    const main = document.querySelector('td.ch-pad:not(.ch-ftr):not([data-test-send-notice])');
    const blocks: Element[] = [];
    const walk = (el: Element) => {
      for (const c of Array.from(el.children)) {
        if (c.tagName === 'TABLE') blocks.push(c);
        else if (c.tagName === 'DIV') walk(c);
      }
    };
    if (main) walk(main);
    const edges = blocks
      .filter((b) => (b as HTMLElement).offsetHeight > 0)
      .map((b) => {
        const r = b.getBoundingClientRect();
        return { l: r.left, r: r.right, what: b.getAttribute('data-block') ?? b.textContent?.trim().slice(0, 30) ?? '' };
      });
    const lines = (el: Element) => {
      const rects = Array.from(el.getClientRects());
      return new Set(rects.map((r) => Math.round(r.top))).size;
    };
    const wrapped: string[] = [];
    document.querySelectorAll('[data-button]').forEach((cell) => {
      const a = cell.querySelector('a');
      if (a && lines(a) > 1) wrapped.push('button: ' + a.textContent?.trim());
    });
    document.querySelectorAll('[data-block="issue"] span[style*="nowrap"]').forEach((s) => {
      if (lines(s) > 1) wrapped.push('title: ' + s.textContent?.trim());
    });
    return { overflow, edges, wrapped };
  });
}

/** Share of differing pixels between two PNG screenshots, decoded in the page itself (no image library needed). */
async function pixelDiff(page: Page, a: Buffer, b: Buffer): Promise<number> {
  return page.evaluate(
    async ([x, y]) => {
      const load = (s: string) =>
        new Promise<HTMLImageElement>((res) => {
          const i = new Image();
          i.onload = () => res(i);
          i.src = 'data:image/png;base64,' + s;
        });
      const [ia, ib] = await Promise.all([load(x), load(y)]);
      const w = Math.max(ia.width, ib.width);
      const h = Math.max(ia.height, ib.height);
      const px = (img: HTMLImageElement) => {
        const c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        const g = c.getContext('2d')!;
        g.fillStyle = '#ff00ff';
        g.fillRect(0, 0, w, h);
        g.drawImage(img, 0, 0);
        return g.getImageData(0, 0, w, h).data;
      };
      const da = px(ia);
      const db = px(ib);
      let diff = 0;
      for (let i = 0; i < da.length; i += 4) {
        if (Math.abs(da[i] - db[i]) + Math.abs(da[i + 1] - db[i + 1]) + Math.abs(da[i + 2] - db[i + 2]) > 48) diff++;
      }
      return diff / (w * h);
    },
    [a.toString('base64'), b.toString('base64')] as const,
  );
}

for (const file of files) {
  test(file, async ({ page }) => {
    for (const width of WIDTHS) {
      for (const mode of ['normal', 'stripped', 'inverted'] as const) {
        await open(page, file, width, mode);
        const m = await measure(page);
        const where = `${file} @${width}px ${mode}`;
        expect(m.overflow, `${where}: horizontal overflow`).toBeLessThanOrEqual(0);
        if (m.edges.length > 0) {
          const l = m.edges[0].l;
          const r = m.edges[0].r;
          for (const e of m.edges) {
            expect(Math.abs(e.l - l), `${where}: block «${e.what}» left edge`).toBeLessThanOrEqual(1);
            expect(Math.abs(e.r - r), `${where}: block «${e.what}» right edge`).toBeLessThanOrEqual(1);
          }
        }
        expect(m.wrapped, `${where}: wrapped labels`).toEqual([]);
      }
    }
    // The render without <style> must be the render with it: the stylesheet is progressive only.
    await open(page, file, 390, 'normal');
    const normal = await page.screenshot({ fullPage: true });
    await open(page, file, 390, 'stripped');
    const stripped = await page.screenshot({ fullPage: true });
    await page.goto('about:blank');
    const share = await pixelDiff(page, normal, stripped);
    expect(share, `${file}: stripped vs normal at 390px`).toBeLessThanOrEqual(0.005);
  });
}
