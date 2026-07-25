import type { ButtonHTMLAttributes, ReactNode } from 'react'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  children: ReactNode
}

const base =
  'inline-flex items-center justify-center gap-2 rounded-xl font-semibold ' +
  'transition-all duration-150 active:scale-[0.98] disabled:opacity-55 disabled:cursor-not-allowed ' +
  'disabled:active:scale-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-1 focus-visible:ring-offset-surface'

const sizes: Record<Size, string> = {
  sm: 'h-9 px-3 text-sm',
  md: 'h-10 px-4 text-sm',
  lg: 'h-11 px-5 text-base',
}

const variants: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white shadow-[var(--shadow-small)] hover:bg-brand-700',
  secondary: 'bg-surface text-text-primary border border-border-strong hover:bg-surface-hover',
  ghost: 'text-text-secondary hover:bg-surface-hover hover:text-text-primary',
  danger: 'bg-danger text-white shadow-[var(--shadow-small)] hover:opacity-90',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading,
  children,
  className = '',
  disabled,
  ...rest
}: ButtonProps) {
  return (
    <button
      className={`${base} ${sizes[size]} ${variants[variant]} ${className}`}
      disabled={disabled || loading}
      {...rest}
    >
      {loading && (
        <span
          className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"
          aria-hidden
        />
      )}
      {children}
    </button>
  )
}
