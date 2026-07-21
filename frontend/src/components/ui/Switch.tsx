interface SwitchProps {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  label?: string
  disabled?: boolean
  id?: string
}

/** Accessible toggle switch (role=switch, keyboard-operable via the underlying button). */
export function Switch({ checked, onCheckedChange, label, disabled, id }: SwitchProps) {
  return (
    <label htmlFor={id} className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-text-primary">
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onCheckedChange(!checked)}
        className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-[var(--radius-pill)] transition-colors disabled:opacity-60 ${
          checked ? 'bg-brand-600' : 'bg-border-strong'
        }`}
      >
        <span
          className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
            checked ? 'translate-x-[18px] rtl:-translate-x-[18px]' : 'translate-x-0.5 rtl:-translate-x-0.5'
          }`}
        />
      </button>
      {label}
    </label>
  )
}
