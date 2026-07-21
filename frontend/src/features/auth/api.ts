import { postData } from '@/lib/api/client'
import type { AuthResult } from '@/lib/api/types'

export interface LoginInput {
  email: string
  password: string
}

export interface RegisterInput {
  tenant_name: string
  name: string
  email: string
  password: string
  password_confirmation: string
}

export function login(input: LoginInput): Promise<AuthResult> {
  return postData<AuthResult>('/auth/login', input)
}

export function register(input: RegisterInput): Promise<AuthResult> {
  return postData<AuthResult>('/auth/register', input)
}

export function logout(): Promise<null> {
  return postData<null>('/auth/logout')
}
