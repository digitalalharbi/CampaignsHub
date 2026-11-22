#!/usr/bin/env python3
"""adstudio — any image -> a consistent batch of 1080x1920 ad creatives (videos + images).

Usage:
  python3 adstudio.py --config job.json --out OUTDIR [--videos 3] [--images 4] [--fonts FONTDIR]

job.json:
{
  "images": ["product.jpg"],              # 1+ source images (first = hero)
  "lang": "ar",                           # ar | en
  "brand": {"name": "Jogh", "logo": "logo.png" | null,
            "accent": "#A8853F" | null, "surface": "#F7F3EE" | null,
            "lock_color": false,          # false (default) = colors follow the image content
            "font_pair": "luxury" | "bold" | "soft" | null},
  "copy": {"hook": "...", "message": "...", "cta": "اطلب الآن",
           "support": "فخامة الشتاء" | null, "price": "299 ر.س" | null},
  "music": "track.mp3" | null,            # null -> generated soft pad (placeholder)
  "date": "21Nov 2026" | null             # naming prefix; null -> today
}
Rules enforced: 1080x1920, video <= 15s (hook 0-3s, message, CTA last 2.5s),
safe margins top 10% / bottom 20% / sides 6% free of any overlay, contrast >= 4.5.
"""
import argparse, json, math, os, subprocess, sys, datetime, random
import numpy as np
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H, FPS = 1080, 1920, 30
SAFE = dict(x0=int(W * .06), x1=int(W * .94), y0=int(H * .10), y1=int(H * .80))
FONT_PAIRS = {
    "luxury": ("IBMPlexSansArabic-Bold.ttf", "IBMPlexSansArabic-Regular.ttf"),
    "bold":   ("Tajawal-ExtraBold.ttf", "Tajawal-Medium.ttf"),
    "soft":   ("Almarai-ExtraBold.ttf", "Almarai-Regular.ttf"),
}
VIOLATIONS = []

# ---------------------------------------------------------------- color utils
def hex2rgb(h): h = h.lstrip('#'); return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))
def rgb2hex(c): return '#%02X%02X%02X' % tuple(int(v) for v in c[:3])
def rel_lum(c):
    def ch(v):
        v = v / 255.0
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    r, g, b = c[:3]; return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)
def contrast(a, b):
    la, lb = rel_lum(a), rel_lum(b); hi, lo = max(la, lb), min(la, lb); return (hi + .05) / (lo + .05)
def rgb2hsv(c):
    import colorsys; return colorsys.rgb_to_hsv(*(v / 255 for v in c[:3]))
def hsv2rgb(h, s, v):
    import colorsys; return tuple(int(round(x * 255)) for x in colorsys.hsv_to_rgb(h, s, v))

# ---------------------------------------------------------------- analysis
def fit_916(img):
    """Return 1.12x oversized 9:16 canvas (for camera moves) + focus point (0..1)."""
    img = img.convert('RGB')
    sw, sh = img.size
    tw, th = int(W * 1.12), int(H * 1.12)
    tr, sr = tw / th, sw / sh
    small = np.asarray(img.resize((96, int(96 * sh / sw)))).astype(np.float32)
    fx, fy = saliency_center(small)
    if abs(sr - tr) / tr < 0.30:            # cover-crop around focus
        if sr > tr:
            nw = sh * tr; x = min(max(fx * sw - nw / 2, 0), sw - nw); box = (x, 0, x + nw, sh)
        else:
            nh = sw / tr; y = min(max(fy * sh - nh / 2, 0), sh - nh); box = (0, y, sw, y + nh)
        out = img.crop(tuple(int(v) for v in box)).resize((tw, th), Image.LANCZOS)
        fxx = (fx * sw - box[0]) / (box[2] - box[0]); fyy = (fy * sh - box[1]) / (box[3] - box[1])
        return out, (fxx, fyy), False
    # blur-fill: never stretch
    bg = img.resize((tw, th), Image.LANCZOS) if False else img.copy()
    s = max(tw / sw, th / sh); bg = img.resize((int(sw * s) + 2, int(sh * s) + 2)).crop((0, 0, tw, th))
    bg = bg.filter(ImageFilter.GaussianBlur(40)).point(lambda v: int(v * .65))
    s = min(tw / sw, th * .62 / sh); fg = img.resize((int(sw * s), int(sh * s)), Image.LANCZOS)
    ox, oy = (tw - fg.width) // 2, int(th * .45 - fg.height / 2)
    bg.paste(fg, (ox, oy))
    return bg, ((ox + fx * fg.width) / tw, (oy + fy * fg.height) / th), True

def saliency_center(a):
    g = a.mean(axis=2)
    gx = np.abs(np.diff(g, axis=1))[:-1, :]; gy = np.abs(np.diff(g, axis=0))[:, :-1]
    mx, mn = a.max(axis=2), a.min(axis=2); sat = ((mx - mn) / (mx + 1e-3))[:-1, :-1]
    m = (gx + gy) * (0.5 + sat)
    h, w = m.shape; yy, xx = np.mgrid[0:h, 0:w]
    # center prior
    m = m * np.exp(-(((xx / w - .5) ** 2) + ((yy / h - .5) ** 2)) / .18)
    t = m.sum() + 1e-6
    return float((m * xx).sum() / t / w), float((m * yy).sum() / t / h)

def edge_map(frame):
    a = np.asarray(frame.convert('L').resize((W // 8, H // 8))).astype(np.float32)
    e = np.zeros_like(a); e[:, 1:] += np.abs(np.diff(a, axis=1)); e[1:, :] += np.abs(np.diff(a, axis=0))
    return e, a

def palette(img, k=5):
    a = np.asarray(img.convert('RGB').resize((64, 114))).reshape(-1, 3).astype(np.float32)
    rng = np.random.default_rng(1); c = a[rng.choice(len(a), k, replace=False)]
    for _ in range(12):
        d = ((a[:, None] - c[None]) ** 2).sum(-1); lab = d.argmin(1)
        c = np.array([a[lab == i].mean(0) if (lab == i).any() else c[i] for i in range(k)])
    cnt = np.bincount(lab, minlength=k)
    return [tuple(map(int, c[i])) for i in cnt.argsort()[::-1]], cnt

def content_hue(img, focus):
    """Signature hue of the content: focus-weighted hue histogram. Warm skin/wood tones are treated as
    background and only win when the content has no other meaningful color."""
    a = np.asarray(img.convert('HSV').resize((90, 160))).reshape(-1, 3).astype(np.float32) / 255
    h, s, v = a[:, 0], a[:, 1], a[:, 2]
    yy, xx = np.mgrid[0:160, 0:90]
    fw = np.exp(-(((xx / 90 - focus[0]) ** 2) + ((yy / 160 - focus[1]) ** 2)) / (2 * .16 ** 2)).reshape(-1)
    w = fw * np.clip(s, 0, .6) * np.clip((v - .10) / .25, 0, 1)
    hd = h * 360; warm = (hd > 8) & (hd < 48) & (s > .18)
    bins = (h * 24).astype(int) % 24
    def peak(mask):
        hist = np.bincount(bins[mask], weights=w[mask], minlength=24)
        sm = hist + .5 * np.roll(hist, 1) + .5 * np.roll(hist, -1)
        return int(sm.argmax()), float(sm.max())
    bw, ww = peak(warm); bo, wo = peak(~warm)
    bi = bo if (wo >= .06 * ww and wo > 0) else bw
    m = (np.abs((bins - bi + 12) % 24 - 12) <= 1) & (w > 0)
    if not m.any(): return None
    wt = w[m]; hh = np.angle((wt * np.exp(2j * np.pi * h[m])).sum()) / (2 * np.pi) % 1
    return hh, float(np.average(s[m], weights=wt)), float(np.average(v[m], weights=wt))

def derive_tokens(img, brand, focus=(.5, .5)):
    """Colors follow the content (default): accent = signature hue of the product area, harmonized;
    surface = light tint of the same hue; text colors solved for contrast. brand.lock_color=true keeps brand colors."""
    cols, _ = palette(img)
    if brand.get('lock_color') and brand.get('accent'):
        accent = hex2rgb(brand['accent']); src = 'brand'
    else:
        ch = content_hue(img, focus)
        if ch and ch[1] > .12:
            hh, ss, vv = ch; accent = hsv2rgb(hh, min(max(ss, .40), .72), min(max(vv, .50), .82)); src = 'content'
        else:   # near-monochrome content: muted tone
            hh = rgb2hsv(cols[0])[0]; accent = hsv2rgb(hh, .22, .60); src = 'content-neutral'
    ha = rgb2hsv(accent)[0]
    surface = hex2rgb(brand['surface']) if (brand.get('lock_color') and brand.get('surface')) else hsv2rgb(ha, .07, .97)
    h, s, v = rgb2hsv(accent); st = hsv2rgb(h, min(max(s, .45), .75), min(v, .45))
    while contrast(st, surface) < 4.5 and v > .05:
        v -= .04; st = hsv2rgb(h, min(max(s, .45), .8), v)
    on_accent = (255, 255, 255) if contrast((255, 255, 255), accent) >= contrast((20, 20, 20), accent) else (20, 20, 20)
    mean_v = np.mean([rgb2hsv(c)[2] for c in cols]); sat = np.mean([rgb2hsv(c)[1] for c in cols])
    pair = brand.get('font_pair') or ('luxury' if sat < .35 and mean_v < .6 else 'bold' if sat > .45 else 'soft')
    return dict(accent=accent, surface=surface, surface_text=st, on_accent=on_accent,
                light=(255, 255, 255), dark=(22, 20, 18), pair=pair, color_source=src, palette=[rgb2hex(c) for c in cols])

# ---------------------------------------------------------------- text layers
class Typo:
    def __init__(self, fontdir, pair, lang):
        d, t = FONT_PAIRS[pair]
        self.dp, self.tp = os.path.join(fontdir, d), os.path.join(fontdir, t)
        self.rtl = lang == 'ar'
    def font(self, display, size): return ImageFont.truetype(self.dp if display else self.tp, int(size), layout_engine=ImageFont.Layout.RAQM)
    def kw(self): return dict(direction='rtl', language='ar') if self.rtl else dict(direction='ltr', language='en')

def wrap(text, font, maxw, kw):
    words, lines, cur = text.split(), [], ''
    for w in words:
        t = (cur + ' ' + w).strip()
        if font.getlength(t, **kw) <= maxw or not cur: cur = t
        else: lines.append(cur); cur = w
    if cur: lines.append(cur)
    if len(lines) > 1 and len(lines[-1].split()) == 1 and maxw > 200:   # avoid orphan word
        return wrap(text, font, maxw * .82, kw)
    return lines

def text_layer(ty, text, size, color, display=True, maxw=None, shadow=True, line_h=1.25, align=None):
    """RGBA layer tightly sized for text (multi-line, shaped)."""
    maxw = maxw or (SAFE['x1'] - SAFE['x0'])
    f = ty.font(display, size); kw = ty.kw()
    while True:
        lines = wrap(text, f, maxw, kw)
        if len(lines) <= 3 or size < 30: break
        size *= .9; f = ty.font(display, size)
    widths = [f.getlength(l, **kw) for l in lines]
    asc, desc = f.getmetrics(); lh = int(size * line_h)
    lw, lhh = int(max(widths)) + 40, lh * (len(lines) - 1) + asc + desc + 40
    lay = Image.new('RGBA', (lw, lhh), (0, 0, 0, 0)); d = ImageDraw.Draw(lay)
    al = align or ('right' if ty.rtl else 'left')
    for i, (l, w) in enumerate(zip(lines, widths)):
        x = lw - 20 - w if al == 'right' else (lw - w) / 2 if al == 'center' else 20
        d.text((x, 20 + i * lh), l, font=f, fill=color, **kw)
    if shadow:
        a = lay.split()[3].filter(ImageFilter.GaussianBlur(int(size * .12)))
        sh = Image.new('RGBA', lay.size, (0, 0, 0, 0)); sh.putalpha(a.point(lambda v: int(v * .55)))
        base = Image.new('RGBA', lay.size, (0, 0, 0, 0)); base.alpha_composite(sh, (0, int(size * .04))); base.alpha_composite(lay)
        lay = base
    return lay

def chip_layer(ty, text, size, bg, fg, radius=6, display=False):
    f = ty.font(display, size); kw = ty.kw()
    tw = f.getlength(text, **kw); asc, desc = f.getmetrics()
    px, py = int(size * .9), int(size * .38)
    lay = Image.new('RGBA', (int(tw + 2 * px), asc + desc + 2 * py), (0, 0, 0, 0)); d = ImageDraw.Draw(lay)
    d.rounded_rectangle((0, 0, lay.width - 1, lay.height - 1), radius=radius, fill=bg + (242,))
    d.text((px, py), text, font=f, fill=fg, **kw)
    return lay

def logo_layer(path, width, ty, name, color):
    if path and os.path.exists(path):
        lg = Image.open(path).convert('RGBA'); s = width / lg.width
        return lg.resize((int(width), int(lg.height * s)), Image.LANCZOS)
    return text_layer(ty, name, max(48, width * .42), (255, 255, 255), display=True, shadow=True)

# ---------------------------------------------------------------- composition
class Placed:
    def __init__(self, layer, x, y, role): self.layer, self.x, self.y, self.role = layer, int(x), int(y), role
    @property
    def box(self): return (self.x, self.y, self.x + self.layer.width, self.y + self.layer.height)

def stack(items, anchor_x, top, gap, rtl, align=None):
    """items: list of (layer, role). Returns placed list; x aligned to anchor side."""
    out, y = [], top
    al = align or ('right' if rtl else 'left')
    for lay, role in items:
        if al == 'right': x = anchor_x - lay.width
        elif al == 'center': x = anchor_x - lay.width / 2
        else: x = anchor_x
        out.append(Placed(lay, x, y, role)); y += lay.height + gap
    return out, y

def block_height(items, gap): return sum(l.height for l, _ in items) + gap * (len(items) - 1)

def choose_band(frame, focus, need_h, prefer):
    """Pick calm vertical band inside live area, avoiding the product focus."""
    e, lum = edge_map(frame)
    cands = []
    for top in range(SAFE['y0'] + 20, SAFE['y1'] - need_h - 10, 24):
        r0, r1 = top // 8, (top + need_h) // 8
        busy = float(e[r0:r1, SAFE['x0'] // 8:SAFE['x1'] // 8].mean())
        fy = focus[1] * H
        overlap = 1.0 if top - 60 < fy < top + need_h + 60 else 0.0
        bias = {'top': top / H, 'bottom': 1 - top / H, 'middle': abs(top + need_h / 2 - H * .45) / H}[prefer]
        cands.append((busy * .04 + overlap * .8 + bias * .9, top))
    return min(cands)[1] if cands else SAFE['y0'] + 20

def ensure_contrast(frame, placed, text_color):
    """Add a soft scrim under the block if needed; returns scrim RGBA (full frame) or None."""
    if not placed: return None
    x0 = min(p.box[0] for p in placed); y0 = min(p.box[1] for p in placed)
    x1 = max(p.box[2] for p in placed); y1 = max(p.box[3] for p in placed)
    reg = np.asarray(frame.crop((max(x0, 0), max(y0, 0), min(x1, W), min(y1, H))).convert('RGB')).reshape(-1, 3)
    worst = np.percentile(reg, 80 if rel_lum(text_color) > .5 else 20, axis=0)
    if contrast(text_color, tuple(worst)) >= 4.5: return None
    sc = Image.new('RGBA', (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(sc)
    dark = rel_lum(text_color) > .5
    pad = 140
    for i in range(pad):
        a = int((.62 if dark else .55) * 255 * (i / pad))
        col = (0, 0, 0, a) if dark else (255, 255, 255, a)
        d.rectangle((0, y0 - pad + i, W, y0 - pad + i + 1), fill=col); d.rectangle((0, y1 + pad - i - 1, W, y1 + pad - i), fill=col)
    d.rectangle((0, y0, W, y1), fill=((0, 0, 0) if dark else (255, 255, 255)) + (int((.62 if dark else .55) * 255),))
    return sc

def check_safe(placed, tag):
    for p in placed:
        x0, y0, x1, y1 = p.box
        # ignore transparent padding: use alpha bbox
        bb = p.layer.split()[3].getbbox()
        if bb: x0, y0, x1, y1 = p.x + bb[0], p.y + bb[1], p.x + bb[2], p.y + bb[3]
        if x0 < SAFE['x0'] or x1 > SAFE['x1'] or y0 < SAFE['y0'] or y1 > SAFE['y1']:
            VIOLATIONS.append(f"{tag}:{p.role} {x0},{y0},{x1},{y1}")

def ink_box(p):
    bb = p.layer.split()[3].getbbox() or (0, 0, p.layer.width, p.layer.height)
    return p.x + bb[0], p.y + bb[1], p.x + bb[2], p.y + bb[3]

def clamp_into_safe(placed):
    """Shift each element (by its visible ink) back inside the live area; shift the whole group vertically."""
    if not placed: return placed
    for p in placed:
        x0, _, x1, _ = ink_box(p)
        if x1 > SAFE['x1']: p.x -= x1 - SAFE['x1']
        if x0 < SAFE['x0']: p.x += SAFE['x0'] - x0
    y0 = min(ink_box(p)[1] for p in placed); y1 = max(ink_box(p)[3] for p in placed)
    dy = max(SAFE['y0'] - y0, 0) - max(y1 - SAFE['y1'], 0)
    for p in placed: p.y += dy
    return placed

# ---------------------------------------------------------------- families (static layouts)
def build_family(fam, ctx, frame, focus):
    ty, tk, c, b = ctx['ty'], ctx['tk'], ctx['copy'], ctx['brand']
    rtl = ty.rtl; ax = SAFE['x1'] + 20 if rtl else SAFE['x0'] - 20
    cx = W // 2 + 0
    light = tk['light']
    cta = chip_layer(ty, c['cta'], 56, tk['accent'], tk['on_accent'], radius=14, display=True)
    logo = logo_layer(b.get('logo'), 120, ty, b['name'], tk['accent'])
    hook_s = {'luxury': 88, 'bold': 100, 'soft': 90}[tk['pair']]
    if fam == 'stacked':
        items = [(logo, 'logo'), (text_layer(ty, c['hook'], hook_s, light, maxw=720), 'hook'),
                 (text_layer(ty, c['message'], 50, light, display=False, maxw=680), 'message')]
        if c.get('support'): items.append((chip_layer(ty, c['support'], 46, tk['surface'], tk['surface_text']), 'support'))
        items.append((cta, 'cta'))
        top = choose_band(frame, focus, block_height(items, 14), 'middle')
        placed, _ = stack(items, ax, top, 14, rtl)
    elif fam == 'bold_hook':
        hook = text_layer(ty, c['hook'], hook_s * 1.35, light, maxw=900, align='center')
        msg = text_layer(ty, c['message'], 52, light, display=False, maxw=820, align='center')
        p1, _ = stack([(hook, 'hook'), (msg, 'message')], cx, SAFE['y0'] + 30, 6, rtl, 'center')
        p2, _ = stack([(logo, 'logo'), (cta, 'cta')], cx, SAFE['y1'] - logo.height - cta.height - 40, 18, rtl, 'center')
        placed = p1 + p2
    elif fam == 'offer':
        badge_txt = c.get('price') or c.get('support') or c['hook']
        badge = chip_layer(ty, badge_txt, 72, tk['accent'], tk['on_accent'], radius=18, display=True)
        items = [(text_layer(ty, c['hook'], hook_s * .9, light, maxw=820), 'hook'), (badge, 'price'),
                 (text_layer(ty, c['message'], 50, light, display=False, maxw=760), 'message'), (cta, 'cta')]
        top = choose_band(frame, focus, block_height(items, 18), 'bottom')
        placed, _ = stack(items, ax, top, 18, rtl)
    elif fam == 'split':
        pw = SAFE['x1'] - SAFE['x0']
        inner = [(text_layer(ty, c['hook'], hook_s * .85, tk['surface_text'], maxw=pw - 80, shadow=False), 'hook'),
                 (text_layer(ty, c['message'], 46, tk['surface_text'], display=False, maxw=pw - 80, shadow=False), 'message'),
                 (cta, 'cta')]
        ph = block_height(inner, 12) + 70
        panel = Image.new('RGBA', (pw, ph), (0, 0, 0, 0))
        ImageDraw.Draw(panel).rounded_rectangle((0, 0, pw - 1, ph - 1), radius=26, fill=tk['surface'] + (245,))
        py = SAFE['y1'] - ph
        pa = Placed(panel, SAFE['x0'], py, 'panel')
        inner_p, _ = stack(inner, (SAFE['x1'] - 20) if rtl else SAFE['x0'] + 20, py + 25, 12, rtl)
        lg, _ = stack([(logo, 'logo')], ax, SAFE['y0'] + 30, 0, rtl)
        placed = [pa] + inner_p + lg
    elif fam == 'editorial':
        fr = Image.new('RGBA', (SAFE['x1'] - SAFE['x0'], SAFE['y1'] - SAFE['y0']), (0, 0, 0, 0))
        ImageDraw.Draw(fr).rectangle((0, 0, fr.width - 1, fr.height - 1), outline=tk['accent'] + (230,), width=3)
        frame_p = Placed(fr, SAFE['x0'], SAFE['y0'], 'frame')
        lg, _ = stack([(logo, 'logo')], cx, SAFE['y0'] + 40, 0, rtl, 'center')
        items = [(text_layer(ty, c['hook'], hook_s, light, maxw=780, align='center'), 'hook'),
                 (text_layer(ty, c['message'], 48, light, display=False, maxw=760, align='center'), 'message'), (cta, 'cta')]
        bh = block_height(items, 12)
        p, _ = stack(items, cx, SAFE['y1'] - bh - 50, 12, rtl, 'center')
        placed = [frame_p] + lg + p
    else:  # minimal
        items = [(logo, 'logo'), (text_layer(ty, c['hook'], hook_s * .9, light, maxw=820, align='center'), 'hook'), (cta, 'cta')]
        bh = block_height(items, 16)
        p, _ = stack(items, cx, SAFE['y1'] - bh - 30, 16, rtl, 'center')
        placed = p
    return clamp_into_safe(placed)

def render_still(fam, ctx, base, focus, path):
    frame = grade(cam(base, focus, 1.06))
    placed = build_family(fam, ctx, frame, focus)
    check_safe([p for p in placed if p.role not in ('frame',)], os.path.basename(path))
    out = frame.convert('RGBA')
    texty = [p for p in placed if p.role in ('hook', 'message', 'logo')]
    sc = ensure_contrast(frame, texty, ctx['tk']['light']) if fam != 'split' else None
    if sc: out.alpha_composite(sc)
    for p in placed: out.alpha_composite(p.layer, (p.x, p.y))
    out.convert('RGB').save(path, quality=95)
    return path

_VIG = {}
def grade(frame):
    key = frame.size
    if key not in _VIG:
        yy, xx = np.mgrid[0:frame.height, 0:frame.width]
        r = np.sqrt(((xx / frame.width - .5) * 1.1) ** 2 + ((yy / frame.height - .5)) ** 2)
        v = (1 - np.clip((r - .45) / .5, 0, 1) * .28) * 1.04
        _VIG[key] = (v[..., None].astype(np.float32), np.float32(128 - 128 * 1.04))
    v, off = _VIG[key]
    a = np.asarray(frame, dtype=np.float32) * v + off
    return Image.fromarray(np.clip(a, 0, 255).astype(np.uint8))

# ---------------------------------------------------------------- motion
def ease_out_cubic(t): t = min(max(t, 0), 1); return 1 - (1 - t) ** 3
def ease_out_quart(t): t = min(max(t, 0), 1); return 1 - (1 - t) ** 4
def ease_io(t): t = min(max(t, 0), 1); return -(math.cos(math.pi * t) - 1) / 2
def prog(t, a, b): return 0 if t <= a else 1 if t >= b else (t - a) / (b - a)

def cam(base, focus, z, pan=(0, 0)):
    """crop base around focus at zoom z (1 = full 1.12x canvas fits)."""
    bw, bh = base.size; cw, ch = bw / z, bh / z
    cx = min(max(focus[0] * bw + pan[0], cw / 2), bw - cw / 2); cy = min(max(focus[1] * bh + pan[1], ch / 2), bh - ch / 2)
    box = (cx - cw / 2, cy - ch / 2, cx + cw / 2, cy + ch / 2)
    return base.resize((W, H), Image.BILINEAR, box=box)

def apply_anim(out, p, t, start, dur=.35, kind='rise', end=None, end_dur=.25):
    a = ease_out_cubic(prog(t, start, start + dur))
    if end is not None: a *= 1 - ease_io(prog(t, end, end + end_dur))
    if a <= 0.001: return
    lay = p.layer
    if kind == 'wipe':  # RTL/LTR mask reveal, keeps Arabic shaping intact
        w = max(1, int(lay.width * a)); rtl = True
        crop = lay.crop((lay.width - w, 0, lay.width, lay.height)) if rtl else lay.crop((0, 0, w, lay.height))
        out.alpha_composite(crop, (p.x + lay.width - w if rtl else p.x, p.y)); return
    if kind == 'grow':  # scaleX from anchor side
        w = max(1, int(lay.width * ease_out_quart(prog(t, start, start + dur))))
        if end is not None: w = max(1, int(w * (1 - ease_io(prog(t, end, end + end_dur)))))
        out.alpha_composite(lay.resize((w, lay.height)), (p.x + lay.width - w, p.y)); return
    if kind == 'pop':
        s = .85 + .15 * ease_out_quart(prog(t, start, start + dur))
        l2 = lay.resize((max(1, int(lay.width * s)), max(1, int(lay.height * s))))
        if a < 1: l2 = fade(l2, a)
        out.alpha_composite(l2, (int(p.x + (lay.width - l2.width) / 2), int(p.y + (lay.height - l2.height) / 2))); return
    dy = int((1 - a) * 18) if kind == 'rise' else 0
    out.alpha_composite(fade(lay, a) if a < 1 else lay, (p.x, p.y + dy))

def fade(lay, a):
    l = lay.copy(); l.putalpha(l.split()[3].point(lambda v: int(v * a))); return l

def video_plan(fam, ctx, base, focus, dur):
    """Return per-frame render function. Structure: hook 0-3s, message, CTA last 2.5s."""
    ty, tk, c, b = ctx['ty'], ctx['tk'], ctx['copy'], ctx['brand']
    rtl = ty.rtl; ax = SAFE['x1'] + 20 if rtl else SAFE['x0'] - 20; cx = W // 2
    light = tk['light']; cta_t = dur - 2.5
    hook_s = {'luxury': 92, 'bold': 104, 'soft': 94}[tk['pair']]
    probe = grade(cam(base, focus, 1.0))
    logo = logo_layer(b.get('logo'), 130, ty, b['name'], tk['accent'])
    cta = chip_layer(ty, c['cta'], 60, tk['accent'], tk['on_accent'], radius=14, display=True)
    cta_items = [(logo, 'logo'), (cta, 'cta')]
    cta_p, _ = stack(cta_items, cx, SAFE['y1'] - block_height(cta_items, 22) - 40, 22, rtl, 'center')
    msg_items = [(text_layer(ty, c['message'], 54, light, display=False, maxw=780), 'message')]
    if c.get('support'): msg_items.append((chip_layer(ty, c['support'], 48, tk['surface'], tk['surface_text']), 'support'))
    if c.get('price'): msg_items.append((chip_layer(ty, c['price'], 64, tk['accent'], tk['on_accent'], radius=14, display=True), 'price'))
    if fam == 'stacked':      # Jogh-like: side-aligned block, mask-wipe hook, stacked reveal
        hook = text_layer(ty, c['hook'], hook_s, light, maxw=760)
        top = choose_band(probe, focus, hook.height + 40, 'middle')
        hook_p, _ = stack([(hook, 'hook')], ax, top, 0, rtl)
        mi = [(logo, 'logo')] + msg_items
        top = choose_band(probe, focus, block_height(mi, 14) + 40, 'middle')
        msg_p, _ = stack(mi, ax, top, 14, rtl)
    elif fam == 'bold_hook':  # huge centered hook with punch-in, message on a surface card
        hook = text_layer(ty, c['hook'], hook_s * 1.3, light, maxw=920, align='center')
        hook_p, _ = stack([(hook, 'hook')], cx, SAFE['y0'] + 80, 0, rtl, 'center')
        pw = SAFE['x1'] - SAFE['x0']
        inner = [(text_layer(ty, c['message'], 50, tk['surface_text'], display=False, maxw=pw - 90, shadow=False, align='center'), 'message')]
        if c.get('price'): inner.append((chip_layer(ty, c['price'], 60, tk['accent'], tk['on_accent'], radius=14, display=True), 'price'))
        ph = block_height(inner, 14) + 70
        panel = Image.new('RGBA', (pw, ph), (0, 0, 0, 0))
        ImageDraw.Draw(panel).rounded_rectangle((0, 0, pw - 1, ph - 1), radius=28, fill=tk['surface'] + (240,))
        py = SAFE['y1'] - ph - 10
        ip, _ = stack(inner, cx, py + 35, 14, rtl, 'center')
        msg_p = [Placed(panel, SAFE['x0'], py, 'panel')] + ip
        if c.get('support'):
            sp, _ = stack([(chip_layer(ty, c['support'], 46, tk['accent'], tk['on_accent'], radius=10), 'support')], cx, py - 90, 0, rtl, 'center')
            msg_p = sp + msg_p
    else:                     # cuts: detail shots, one message beat per cut
        hook = text_layer(ty, c['hook'], hook_s * 1.1, light, maxw=900, align='center')
        hook_p, _ = stack([(hook, 'hook')], cx, SAFE['y0'] + 60, 0, rtl, 'center')
        top = choose_band(probe, focus, block_height(msg_items, 14) + 40, 'bottom')
        msg_p, _ = stack(msg_items, ax, top, 14, rtl)
    for grp in (cta_p, hook_p, msg_p): clamp_into_safe(grp); check_safe([q for q in grp if q.role != 'panel'], fam + '_video')
    sc_hook = ensure_contrast(probe, hook_p, light)
    sc_msg = None if fam == 'bold_hook' else ensure_contrast(probe, [p for p in msg_p if p.role in ('message', 'logo')], light)
    sc_cta = Image.new('RGBA', (W, H), (0, 0, 0, 120))

    # camera shots
    if fam == 'cuts':
        shots = [(0, 1.0, 1.10, (0, 0)), (3.0, 1.32, 1.40, (-60, -40)), (5.2, 1.30, 1.38, (70, 90)),
                 (7.4, 1.18, 1.24, (0, -80)), (cta_t, 1.0, 1.05, (0, 0))]
    elif fam == 'bold_hook':
        shots = [(0, 1.22, 1.04, (0, 0)), (3.0, 1.04, 1.12, (0, 0)), (cta_t, 1.12, 1.15, (0, 0))]
    else:
        shots = [(0, 1.10, 1.04, (0, 0)), (3.0, 1.04, 1.08, (0, 0)), (cta_t, 1.08, 1.10, (0, 0))]

    def frame_at(t):
        i = max(k for k, s in enumerate(shots) if s[0] <= t)
        s0 = shots[i][0]; s1 = shots[i + 1][0] if i + 1 < len(shots) else dur
        z = shots[i][1] + (shots[i][2] - shots[i][1]) * (ease_out_quart(prog(t, s0, s0 + .9)) * .6 + prog(t, s0, s1) * .4)
        fr = grade(cam(base, focus, z, shots[i][3])).convert('RGBA') if True else None
        # flash on cut
        if i > 0 and t - s0 < .08: fr.alpha_composite(Image.new('RGBA', (W, H), (255, 255, 255, int(70 * (1 - (t - s0) / .08)))))
        # HOOK 0-3
        if t < 3.2:
            if sc_hook: fr.alpha_composite(fade(sc_hook, 1 - ease_io(prog(t, 2.8, 3.2))))
            for p in hook_p:
                if fam == 'bold_hook': apply_anim(fr, p, t, .15, .45, 'pop', end=2.8, end_dur=.3)
                else: apply_anim(fr, p, t, .2, .6, 'wipe' if fam == 'stacked' else 'rise', end=2.8, end_dur=.3)
        # MESSAGE 3 - cta_t
        if 3.0 <= t < cta_t + .3:
            if sc_msg: fr.alpha_composite(fade(sc_msg, ease_io(prog(t, 3.0, 3.4)) * (1 - ease_io(prog(t, cta_t - .1, cta_t + .25)))))
            for k, p in enumerate(msg_p):
                st = (shots[min(k + 1, len(shots) - 2)][0] + .15) if fam == 'cuts' else 3.15 + k * .22
                kind = 'grow' if p.role in ('support', 'price') else 'rise'
                if p.role == 'panel': kind = 'rise'
                apply_anim(fr, p, t, st, .4, kind, end=cta_t - .15 + (len(msg_p) - k) * .06, end_dur=.25)
        # CTA last 2.5s (static & readable)
        if t >= cta_t:
            fr.alpha_composite(fade(sc_cta, ease_io(prog(t, cta_t, cta_t + .4))))
            for k, p in enumerate(cta_p): apply_anim(fr, p, t, cta_t + .1 + k * .15, .4, 'pop' if p.role == 'cta' else 'rise')
        return fr.convert('RGB')
    return frame_at

def make_pad(path, dur):
    """Placeholder royalty-free bed generated locally (replace with a licensed track via 'music')."""
    expr = ("0.10*sin(2*PI*110*t)*(0.6+0.4*sin(2*PI*0.25*t))+0.07*sin(2*PI*164.81*t)+0.06*sin(2*PI*220*t)"
            "+0.04*sin(2*PI*329.63*t)*(0.5+0.5*sin(2*PI*0.5*t))+0.05*sin(2*PI*55*t)*exp(-6*mod(t,0.75))")
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', f"aevalsrc='{expr}':s=44100:d={dur}",
                    '-af', f'lowpass=f=1800,afade=t=in:d=0.6,afade=t=out:st={dur - 0.8}:d=0.8,volume=1.6',
                    '-c:a', 'aac', '-b:a', '128k', path], check=True)

def render_video(fam, ctx, base, focus, path, dur, music):
    assert dur <= 15, 'max 15s'
    f = video_plan(fam, ctx, base, focus, dur)
    tmp = path + '.v.mp4'
    p = subprocess.Popen(['ffmpeg', '-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', f'{W}x{H}', '-r', str(FPS),
                          '-i', '-', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p',
                          '-profile:v', 'high', '-movflags', '+faststart', tmp], stdin=subprocess.PIPE)
    n = int(dur * FPS)
    for i in range(n): p.stdin.write(f(i / FPS).tobytes())
    p.stdin.close(); p.wait()
    a = music or (path + '.pad.m4a')
    if not music: make_pad(a, dur)
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', tmp, '-i', a, '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac',
                    '-b:a', '128k', '-af', f'afade=t=out:st={dur - 0.6:.2f}:d=0.6', '-shortest', path], check=True)
    os.remove(tmp)
    if not music: os.remove(a)
    # cover = end of hook
    f(2.4).save(path.replace('.mp4', '_cover.jpg'), quality=92)
    return path

def qa_sheet(paths, out):
    th = [Image.open(p).convert('RGB').resize((270, 480)) for p in paths]
    sheet = Image.new('RGB', (280 * len(th), 490), (30, 30, 30)); d = ImageDraw.Draw(sheet)
    for i, t in enumerate(th):
        o = t.convert('RGBA'); ov = Image.new('RGBA', o.size, (0, 0, 0, 0)); od = ImageDraw.Draw(ov)
        od.rectangle((0, 0, 270, 48), fill=(255, 40, 40, 70)); od.rectangle((0, 384, 270, 480), fill=(255, 40, 40, 70))
        o.alpha_composite(ov); sheet.paste(o.convert('RGB'), (5 + i * 280, 5))
    sheet.save(out)

# ---------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--config', required=True); ap.add_argument('--out', required=True)
    ap.add_argument('--videos', type=int, default=3); ap.add_argument('--images', type=int, default=4)
    ap.add_argument('--fonts', default=os.path.expanduser('~/adstudio_fonts')); ap.add_argument('--duration', type=float, default=12)
    a = ap.parse_args()
    cfg = json.load(open(a.config)); os.makedirs(a.out, exist_ok=True)
    base_dir = os.path.dirname(os.path.abspath(a.config))
    srcs = [p if os.path.isabs(p) else os.path.join(base_dir, p) for p in cfg['images']]
    brand = cfg.get('brand', {}); lang = cfg.get('lang', 'ar')
    if brand.get('logo') and not os.path.isabs(brand['logo']): brand['logo'] = os.path.join(base_dir, brand['logo'])
    date = cfg.get('date') or datetime.date.today().strftime('%d%b %Y').lstrip('0')
    bname = ''.join(ch for ch in (brand.get('name_en') or brand.get('name', 'Brand')) if ch.isalnum() or ch == ' ').strip().replace(' ', '') or 'Brand'
    hero = Image.open(srcs[0]); base, focus, filled = fit_916(hero)
    tk = derive_tokens(base, brand, focus); ty = Typo(a.fonts, tk['pair'], lang)
    ctx = dict(ty=ty, tk=tk, copy=cfg['copy'], brand=dict(brand, name=brand.get('name', 'Brand')))
    dur = min(a.duration, 15)
    outs, report = [], dict(tokens={k: (rgb2hex(v) if isinstance(v, tuple) else v) for k, v in tk.items()}, filled_bg=filled, files=[])
    vfams = ['stacked', 'bold_hook', 'cuts'][:a.videos]
    sfams = ['stacked', 'bold_hook', 'offer', 'split', 'editorial', 'minimal']
    if not (cfg['copy'].get('price') or cfg['copy'].get('support')): sfams.remove('offer')
    sfams = sfams[:a.images]
    for i, fam in enumerate(sfams):
        src = srcs[i % len(srcs)]
        b2, f2, _ = (base, focus, filled) if i % len(srcs) == 0 else fit_916(Image.open(src))
        p = os.path.join(a.out, f"{date}ــ{bname}ــIMG-{fam}ــ9x16.jpg")
        render_still(fam, ctx, b2, f2, p); outs.append(p); report['files'].append(p)
    for fam in vfams:
        p = os.path.join(a.out, f"{date}ــ{bname}ــVID-{fam}ــ9x16.mp4")
        print('rendering', fam, file=sys.stderr)
        render_video(fam, ctx, base, focus, p, dur, cfg.get('music')); report['files'].append(p)
        outs.append(p.replace('.mp4', '_cover.jpg'))
    qa_sheet(outs, os.path.join(a.out, 'QA_safe_zones.jpg'))
    report['safe_zone_violations'] = VIOLATIONS
    report['duration_s'] = dur
    json.dump(report, open(os.path.join(a.out, 'report.json'), 'w'), ensure_ascii=False, indent=2)
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if VIOLATIONS: sys.exit(2)

if __name__ == '__main__':
    main()