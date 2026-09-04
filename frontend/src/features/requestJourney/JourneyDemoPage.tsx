import { useState } from 'react'
import { GitBranch } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { JourneyControl } from './JourneyControl'
import { REQUEST_STAGES, stageLabel, type RequestStage } from './api'

const COPY = {
  ar: {
    title: 'التحكم بمسار الطلب', subtitle: 'أداة تشغيلية: أدخل معرّف طلب واختر مرحلته الحالية لتنفيذ الانتقالات الصالحة عبر واجهة الحالة الحقيقية.',
    request_id: 'معرّف الطلب', request_id_ph: 'UUID الطلب…', start_stage: 'المرحلة الحالية',
    load: 'عرض التحكم', note: 'كل انتقال يستدعي PATCH /app/requests/{id}/journey ويعيد التحقق في الخادم.',
    enter: 'أدخل معرّف طلب صحيح لعرض عنصر التحكم.',
  },
  en: {
    title: 'Request journey control', subtitle: 'An operational tool: enter a request id and pick its current stage to run valid transitions through the real journey endpoint.',
    request_id: 'Request id', request_id_ph: 'Request UUID…', start_stage: 'Current stage',
    load: 'Show control', note: 'Each transition calls PATCH /app/requests/{id}/journey and is re-validated on the server.',
    enter: 'Enter a valid request id to show the control.',
  },
}

export function JourneyDemoPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = ar ? COPY.ar : COPY.en

  const [idInput, setIdInput] = useState('')
  const [stageInput, setStageInput] = useState<RequestStage>('submitted')
  const [loaded, setLoaded] = useState<{ id: string; stage: RequestStage } | null>(null)

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-3xl font-extrabold tracking-tight text-text-primary">
          <GitBranch size={26} className="text-brand-600" /> {c.title}
        </h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <form
        onSubmit={(e) => { e.preventDefault(); if (idInput.trim()) setLoaded({ id: idInput.trim(), stage: stageInput }) }}
        className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
      >
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.request_id}
          <input
            required
            value={idInput}
            onChange={(e) => setIdInput(e.target.value)}
            placeholder={c.request_id_ph}
            dir="ltr"
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary"
          />
        </label>
        <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.start_stage}
          <select
            value={stageInput}
            onChange={(e) => setStageInput(e.target.value as RequestStage)}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary"
          >
            {REQUEST_STAGES.map((s) => <option key={s} value={s}>{stageLabel(s, ar)}</option>)}
          </select>
        </label>
        <button type="submit" className="w-fit rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700">
          {c.load}
        </button>
        <p className="text-[11px] text-text-muted">{c.note}</p>
      </form>

      {loaded ? (
        <JourneyControl
          key={loaded.id + loaded.stage}
          requestId={loaded.id}
          currentStage={loaded.stage}
          onTransitioned={(res) => setLoaded((prev) => (prev ? { ...prev, stage: res.journey_stage as RequestStage } : prev))}
        />
      ) : (
        <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.enter}</p>
      )}
    </div>
  )
}
