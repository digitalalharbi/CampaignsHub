import { EmptyState } from '@/components/ui/States'

interface ComingSoonPageProps {
  titleKey: string
  descriptionKey?: string
}

export function ComingSoonPage({ titleKey, descriptionKey }: ComingSoonPageProps) {
  
  return (
    <section className="flex h-[70vh] flex-col items-center justify-center space-y-4">
      <EmptyState
        title={titleKey}
        description={
          descriptionKey ||
          'This module is currently under development and will be available in the next major update.'
        }
      />
    </section>
  )
}
