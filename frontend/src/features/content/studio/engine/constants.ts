/** CREATIVE-ENGINE-001 — the constants the reference enforces, and nothing may break: 1080×1920 at 30fps; top 10%, bottom 20% and 6% on each side free of any overlay. */
export const W = 1080
export const H = 1920
export const FPS = 30
export const SAFE = { x0: Math.trunc(W * 0.06), x1: Math.trunc(W * 0.94), y0: Math.trunc(H * 0.1), y1: Math.trunc(H * 0.8) } as const
/** The camera canvas is 1.12× the frame, so a push-in or a pan never shows an edge. */
export const OVERSIZE = 1.12
export const MIN_CONTRAST = 4.5
export const MAX_VIDEO_S = 15
