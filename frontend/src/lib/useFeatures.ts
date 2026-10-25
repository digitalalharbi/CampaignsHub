import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

interface BrandResponse {
  features?: { sales_crm_enabled?: boolean }
}

/** Reads platform/tenant feature flags from GET /api/v1/brand (cached). */
export function useFeatures() {
  const query = useQuery({
    queryKey: ['brand'],
    queryFn: () => getData<BrandResponse>('/brand'),
    staleTime: 5 * 60_000,
  })
  return {
    salesCrmEnabled: query.data?.features?.sales_crm_enabled ?? false,
  }
}
