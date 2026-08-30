import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronsUpDown } from 'lucide-react'
import { listProjects } from '@/features/projects/api'
import { useProject } from '@/stores/project'
import { Button } from '@/components/ui/Button'
import { useT } from '@/lib/i18n'

export function ProjectSwitcher() {
  const t = useT()
  const { currentProjectId, setCurrentProjectId } = useProject()

  const { data: projects = [], isLoading } = useQuery({
    queryKey: ['projects', 'list'],
    queryFn: () => listProjects(false),
  })

  // Validate the persisted project (a stale id after a re-seed no longer exists) and, when none is
  // valid, prefer the rich demo analytics project so the first screen isn't empty for the demo admin.
  useEffect(() => {
    if (projects.length === 0) return
    const isValid = projects.some((p) => p.id === currentProjectId)
    if (!isValid) {
      const preferred = projects.find((p) => /متجر تجريبي|demo store/i.test(p.name)) ?? projects[0]
      setCurrentProjectId(preferred.id)
    }
  }, [currentProjectId, projects, setCurrentProjectId])

  if (isLoading) {
    return <div className="h-9 w-full animate-pulse rounded-md bg-surface-secondary" />
  }

  if (projects.length === 0) {
    return (
      <Button variant="secondary" className="w-full justify-start text-sm text-text-muted" disabled>
        {t('no_projects')}
      </Button>
    )
  }

  const selectedProject = projects.find((p) => p.id === currentProjectId) || projects[0]

  return (
    <div className="relative w-full">
      <span className="pointer-events-none absolute start-3 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md bg-brand-100 text-xs font-bold text-brand-700">
        {(selectedProject?.name ?? '?').charAt(0)}
      </span>
      {/*
        A project name longer than the rail was CLIPPED, not shortened.

        The control is a native select inside an RTL shell, and «Growth — Acquisition» is Latin text:
        the overflow fell at the start edge, so the Arabic product showed «rowth — Acquisition» on
        every page, with the first letter cut and nothing saying so.

        Two settings, both needed. The ellipsis says «there is more of this name», where a cut letter
        says the project is called something it is not. And `dir="auto"` lays the name out in its OWN
        direction, so a Latin name truncates at its end — «Growth — Acquisi…», which still names the
        project — rather than at its beginning. An Arabic name is unaffected: it was already reading
        in the shell's direction.
      */}
      <select
        dir="auto"
        title={selectedProject?.name}
        className="w-full cursor-pointer appearance-none overflow-hidden text-ellipsis whitespace-nowrap rounded-xl border border-border bg-surface-secondary py-2.5 pe-9 ps-11 text-sm font-semibold text-text-primary transition-colors hover:border-border-strong focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25"
        value={selectedProject?.id || ''}
        onChange={(e) => setCurrentProjectId(e.target.value)}
      >
        {projects.map((project) => (
          <option key={project.id} value={project.id}>
            {project.name}
          </option>
        ))}
      </select>
      <ChevronsUpDown className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-text-muted" size={16} />
    </div>
  )
}
