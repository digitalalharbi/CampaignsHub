/** Mirrors the backend response envelope (see backend App\Support\ApiResponse). */
export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
  meta: { request_id?: string } & Record<string, unknown>
  errors: Record<string, string[]> | null
}

export interface AuthUser {
  id: string
  name: string
  email: string
  tenant_id: string | null
  is_platform_admin: boolean
  permissions: string[]
  created_at: string | null
}

export interface AuthResult {
  user: AuthUser
  token: string
}

export interface HealthData {
  status: string
  service?: string
  checks?: Record<string, 'up' | 'down'>
}
