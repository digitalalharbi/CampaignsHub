/** ACCESS-CENTER-001 — short labels the access center shares. Same words as the client page (TabPortal). */
export const PORTAL_ROLE_LABEL: Record<string, { ar: string; en: string }> = {
  // CLIENT-INVITE-NO-ROLE-001 — no viewer/approver split shown: what a client sees and does is the client page's switches.
  client_viewer: { ar: 'عميل', en: 'Client' },
  client_approver: { ar: 'عميل', en: 'Client' },
  client_admin: { ar: 'مسؤول العميل', en: 'Client admin' },
}

export const STATUS_LABEL: Record<string, { ar: string; en: string }> = {
  active: { ar: 'نشط', en: 'Active' },
  disabled: { ar: 'معطّل', en: 'Disabled' },
  pending: { ar: 'بانتظار القبول', en: 'Pending' },
  expired: { ar: 'انتهت', en: 'Expired' },
  revoked: { ar: 'مسحوبة', en: 'Withdrawn' },
}

/** What each audited action reads as in the history list. */
export const ACTION_LABEL: Record<string, { ar: string; en: string }> = {
  'settings.team.invited': { ar: 'أُرسلت الدعوة', en: 'Invited' },
  'settings.team.invitation_revoked': { ar: 'سُحبت الدعوة', en: 'Invitation withdrawn' },
  'settings.team.role_changed': { ar: 'تغيّرت الفئة', en: 'Category changed' },
  'settings.team.disabled': { ar: 'عُطّل', en: 'Disabled' },
  'settings.team.enabled': { ar: 'فُعّل', en: 'Enabled' },
  'access.member_overrides_changed': { ar: 'تغيّر التخصيص', en: 'Customisation changed' },
  'access.member_updated': { ar: 'عُدّلت الصلاحيات', en: 'Access changed' },
  'settings.team.removed': { ar: 'أُزيل', en: 'Removed' },
  'settings.roles.updated': { ar: 'عُدّل الدور', en: 'Role changed' },
  'access.invitation_updated': { ar: 'عُدّلت الدعوة', en: 'Invitation changed' },
  'access.invitation_resent': { ar: 'أُعيد الإرسال', en: 'Sent again' },
  'access.category_created': { ar: 'أُنشئت الفئة', en: 'Category created' },
  'access.category_updated': { ar: 'عُدّلت الفئة', en: 'Category changed' },
  'access.category_deleted': { ar: 'حُذفت الفئة', en: 'Category deleted' },
}

/** ACCESS-SECTIONS-001 — the fields an audit row compares, in the access log. */
export const CHANGE_FIELD_LABEL: Record<string, { ar: string; en: string }> = {
  category: { ar: 'الفئة', en: 'Category' },
  role: { ar: 'الفئة', en: 'Category' },
  roles: { ar: 'الفئة', en: 'Category' },
  sections: { ar: 'الأقسام', en: 'Sections' },
  client_ids: { ar: 'العملاء', en: 'Clients' },
  allow: { ar: 'مفاتيح مضافة', en: 'Keys added' },
  deny: { ar: 'مفاتيح محجوبة', en: 'Keys withheld' },
  keys: { ar: 'المفاتيح', en: 'Keys' },
  email: { ar: 'البريد', en: 'Email' },
  name: { ar: 'الاسم', en: 'Name' },
}
