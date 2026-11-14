import { create } from 'zustand'

/**
 * DIALOG-001 — the in-product replacement for `window.confirm` and `window.prompt`.
 *
 * The browser's own boxes cannot be styled, translated, tested or kept on the page's direction, and a prompt
 * blocks the whole tab. These are promise-based so a call site reads the same way the old one did:
 * `if (await confirmDialog({ title })) …`. Drawn by `DialogHost`, mounted once in `Providers`.
 */
export interface ConfirmRequest {
  kind: 'confirm'
  title: string
  body?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  resolve: (ok: boolean) => void
}

export interface PromptRequest {
  kind: 'prompt'
  title: string
  body?: string
  label?: string
  initial?: string
  /** A reason, a name — the dialog refuses an empty answer unless this is false. */
  required?: boolean
  /** A reason that goes to an audit trail has to say something: shorter than this is refused. */
  minLength?: number
  confirmLabel?: string
  cancelLabel?: string
  resolve: (value: string | null) => void
}

type Request = ConfirmRequest | PromptRequest

interface DialogState {
  current: Request | null
  queue: Request[]
  push: (r: Request) => void
  close: () => void
}

export const useDialogs = create<DialogState>((set, get) => ({
  current: null,
  queue: [],
  push: (r) => (get().current ? set((s) => ({ queue: [...s.queue, r] })) : set({ current: r })),
  close: () => set((s) => ({ current: s.queue[0] ?? null, queue: s.queue.slice(1) })),
}))

export function confirmDialog(opts: Omit<ConfirmRequest, 'kind' | 'resolve'>): Promise<boolean> {
  return new Promise((resolve) => useDialogs.getState().push({ ...opts, kind: 'confirm', resolve }))
}

/** Resolves to the typed text, or `null` when cancelled. */
export function promptDialog(opts: Omit<PromptRequest, 'kind' | 'resolve'>): Promise<string | null> {
  return new Promise((resolve) => useDialogs.getState().push({ ...opts, kind: 'prompt', resolve }))
}
