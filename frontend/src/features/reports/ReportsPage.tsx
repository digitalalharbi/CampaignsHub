import { useQuery } from '@tanstack/react-query'
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts'
import { getProjectReports } from './api'
import { Card, CardTitle } from '@/components/ui/Card'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useT } from '@/lib/i18n'
import { useProject } from '@/stores/project'

export function ReportsPage() {
  const t = useT()
  const { currentProjectId } = useProject()

  const query = useQuery({
    queryKey: ['project', currentProjectId, 'reports'],
    queryFn: () => getProjectReports(currentProjectId!),
    enabled: Boolean(currentProjectId),
  })

  if (!currentProjectId) {
    return (
      <div className="flex h-64 flex-col items-center justify-center rounded-lg border border-dashed border-border text-text-muted">
        {t('no_project_selected')}
      </div>
    )
  }

  if (query.isLoading) {
    return (
      <div className="space-y-4">
        <div className="grid gap-4 md:grid-cols-3">
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-28 w-full" />
        </div>
        <Skeleton className="h-96 w-full" />
      </div>
    )
  }

  if (query.isError) {
    return <ErrorState title={t('error')} onRetry={() => query.refetch()} />
  }

  const { kpis, chart } = query.data!

  return (
    <section className="space-y-6">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('reports')}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">
          Comprehensive performance metrics for the current project.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Card className="bg-gradient-to-br from-brand-500 to-brand-600 text-white">
          <CardTitle><span className="text-white/80">Total Spend</span></CardTitle>
          <div className="mt-2 text-3xl font-extrabold">
            ${kpis.total_spend.toLocaleString()}
          </div>
          <div className="mt-1 text-[12px] text-white/60">
            Out of ${kpis.total_budget.toLocaleString()}
          </div>
        </Card>
        <Card>
          <CardTitle><span className="text-text-secondary">Total Impressions</span></CardTitle>
          <div className="mt-2 text-3xl font-extrabold text-text-primary">
            {kpis.total_impressions.toLocaleString()}
          </div>
          <div className="mt-1 text-[12px] text-text-muted">
            Across {kpis.active_campaigns} active campaigns
          </div>
        </Card>
        <Card>
          <CardTitle><span className="text-text-secondary">Total Clicks</span></CardTitle>
          <div className="mt-2 text-3xl font-extrabold text-text-primary">
            {kpis.total_clicks.toLocaleString()}
          </div>
          <div className="mt-1 text-[12px] text-text-muted">
            {((kpis.total_clicks / (kpis.total_impressions || 1)) * 100).toFixed(2)}% CTR
          </div>
        </Card>
      </div>

      <Card className="p-6">
        <h3 className="mb-6 font-[var(--font-heading)] text-lg font-bold">Performance Over Time</h3>
        <div className="h-[400px] w-full">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chart} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
              <XAxis 
                dataKey="date" 
                stroke="var(--color-text-muted)" 
                fontSize={12} 
                tickLine={false} 
                axisLine={false} 
              />
              <YAxis 
                yAxisId="left" 
                stroke="var(--color-text-muted)" 
                fontSize={12} 
                tickLine={false} 
                axisLine={false} 
              />
              <YAxis 
                yAxisId="right" 
                orientation="right" 
                stroke="var(--color-text-muted)" 
                fontSize={12} 
                tickLine={false} 
                axisLine={false} 
              />
              <Tooltip
                contentStyle={{
                  backgroundColor: 'var(--color-surface)',
                  border: '1px solid var(--color-border)',
                  borderRadius: '8px',
                  boxShadow: 'var(--shadow-medium)'
                }}
              />
              <Legend wrapperStyle={{ paddingTop: '20px' }} />
              <Line
                yAxisId="left"
                type="monotone"
                name="Impressions"
                dataKey="impressions"
                stroke="var(--color-brand-500)"
                strokeWidth={3}
                dot={{ r: 4, strokeWidth: 2 }}
                activeDot={{ r: 6 }}
              />
              <Line
                yAxisId="right"
                type="monotone"
                name="Clicks"
                dataKey="clicks"
                stroke="#10B981"
                strokeWidth={3}
                dot={{ r: 4, strokeWidth: 2 }}
                activeDot={{ r: 6 }}
              />
              <Line
                yAxisId="right"
                type="monotone"
                name="Spend ($)"
                dataKey="spend"
                stroke="#F59E0B"
                strokeWidth={3}
                dot={{ r: 4, strokeWidth: 2 }}
                activeDot={{ r: 6 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>
    </section>
  )
}
