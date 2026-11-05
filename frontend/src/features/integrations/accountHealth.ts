/**
 * An account's state in one phrase — RUNTIME-100 §31.
 *
 * Each of these names a different next action, which is the only reason to tell them apart. «مربوط
 * بمشروع» said all of them at once, so an account whose access had been withdrawn and one syncing
 * happily read identically.
 */
export function accountHealthLabel(health: string | null | undefined, ar: boolean): string {
  switch (health) {
    case 'healthy': return ar ? 'تعمل' : 'Healthy'
    case 'pending_first_sync': return ar ? 'بانتظار أول مزامنة' : 'First sync pending'
    case 'delayed': return ar ? 'متأخرة' : 'Delayed'
    case 'failed': return ar ? 'فشلت آخر محاولة' : 'Last attempt failed'
    /*
      META-INSIGHTS-GRANT-001 — an ASSET grant, named on the account it is missing from.
      «(#200) Ad account owner has NOT grant ads_management or ads_read permission» is decided per ad
      account in Business Manager, so one authorisation can hold this account refused and its
      siblings fine. «فشلت آخر محاولة» sent a reader to the logs; this sends them to the grant.
      `ads_read` alone: it is sufficient for insights, and naming `business_management` would ask
      for a capability this product does not need.
    */
    case 'insights_not_authorised': return ar ? 'لم تُمنح صلاحية ads_read لهذا الحساب' : 'ads_read is not granted on this account'
    case 'access_lost': return ar ? 'تعذّر الوصول' : 'Access lost'
    case 'revoked': return ar ? 'الربط ملغى' : 'Connection revoked'
    // Assigned but the server did not say — an older response, not a state worth inventing a word for.
    default: return ar ? 'مربوط بمشروع' : 'Connected'
  }
}

/** Colour carries the same distinction the words do, so the state survives a glance. */
export function accountHealthTone(health: string | null | undefined): string {
  switch (health) {
    case 'healthy': return 'text-success'
    case 'delayed': return 'text-warning'
    case 'failed':
    case 'insights_not_authorised':
    case 'access_lost':
    case 'revoked': return 'text-danger'
    default: return 'text-text-muted'
  }
}

/** The same distinction as {@link accountHealthTone}, as a `Badge` tone — for rows that draw a chip. */
export function accountHealthBadgeTone(health: string | null | undefined): 'success' | 'warning' | 'danger' | 'neutral' {
  switch (health) {
    case 'healthy': return 'success'
    case 'delayed': return 'warning'
    case 'failed':
    case 'insights_not_authorised':
    case 'access_lost':
    case 'revoked': return 'danger'
    default: return 'neutral'
  }
}
