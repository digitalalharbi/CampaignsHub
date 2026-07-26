import { Link } from 'react-router-dom'
import { ArrowLeft, Hammer } from 'lucide-react'

/** Temporary section shell during the Settings build-out — links to the working surface when one exists. */
export function PlaceholderTab({ title, hint, link, linkLabel }: { title: string; hint: string; link?: string; linkLabel?: string }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <div className="mb-2 flex items-center gap-2">
        <Hammer size={18} className="text-brand-600" />
        <h2 className="text-xl font-bold text-text-primary">{title}</h2>
      </div>
      <p className="text-sm text-text-secondary">{hint}</p>
      {link && (
        <Link to={link} className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
          {linkLabel ?? 'فتح'} <ArrowLeft size={15} />
        </Link>
      )}
    </div>
  )
}
