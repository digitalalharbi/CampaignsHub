import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

export interface LoginInput {
  email: string
  password: string
  remember?: boolean
}

export interface RegisterInput {
  tenant_name: string
  name: string
  email: string
  password: string
  password_confirmation: string
}

interface UserEnvelope {
  user: AuthUser
}

export async function login(input: LoginInput): Promise<AuthUser> {
  await ensureCsrfCookie()
  const { user } = await postData<UserEnvelope>('/auth/login', input)
  return user
}

export async function register(input: RegisterInput): Promise<AuthUser> {
  await ensureCsrfCookie()
  const { user } = await postData<UserEnvelope>('/auth/register', input)
  return user
}

export async function logout(): Promise<void> {
  await postData<null>('/auth/logout')
}

/** Probe the session on app load; returns null when the visitor is a guest. */
export async function fetchCurrentUser(): Promise<AuthUser | null> {
  try {
    const { user } = await getData<UserEnvelope>('/auth/me')
    return user
  } catch {
    return null
  }
}
