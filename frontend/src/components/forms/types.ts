import type { Locale } from '@/stores/ui'

/**
 * A single selectable value. Pure data — the option engine (taxonomy) or a page maps its
 * records into this shape. Labels are bilingual: `label_ar`/`label_en` are preferred and
 * resolved by locale; `label` is a pre-resolved fallback so plain callers can pass one string.
 */
export interface Option {
  value: string
  label?: string
  label_ar?: string
  label_en?: string
  description?: string | null
  color?: string | null
  /** Free-form icon token (emoji or short text). Rendered as a leading glyph, never executed. */
  icon?: string | null
  disabled?: boolean
  /** Grouping bucket (MultiSelectField renders grouped headers). */
  group?: string | null
  /** Parent value for hierarchical trees. Root options have null/undefined. */
  parentValue?: string | null
  /** System options are immutable except for label/color/active — surfaced as a lock hint. */
  isSystem?: boolean
}

/** Payload the OptionDrawer collects and hands to an injected create function. */
export interface OptionDraft {
  label_ar: string
  label_en: string
  description?: string
  color?: string
  icon?: string
  parentValue?: string | null
}

/** Props shared by every field wrapper (label/hint/error/state). */
export interface BaseFieldProps {
  label?: string
  hint?: string
  error?: string
  required?: boolean
  disabled?: boolean
  id?: string
  name?: string
  className?: string
}

/** Resolve an option's display label for the active locale (Arabic-first). */
export function optionLabel(option: Option, locale: Locale): string {
  if (locale === 'ar' && option.label_ar) return option.label_ar
  if (locale === 'en' && option.label_en) return option.label_en
  return option.label ?? option.label_ar ?? option.label_en ?? option.value
}

/** Case-insensitive match across every label variant + value + description. */
export function matchesQuery(option: Option, query: string, locale: Locale): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  const haystack = [
    optionLabel(option, locale),
    option.label,
    option.label_ar,
    option.label_en,
    option.value,
    option.description ?? '',
  ]
  return haystack.some((h) => (h ?? '').toLowerCase().includes(q))
}

/** Filter a list by a free-text query (no-op for an empty query). */
export function filterOptions(options: Option[], query: string, locale: Locale): Option[] {
  const q = query.trim()
  if (!q) return options
  return options.filter((o) => matchesQuery(o, q, locale))
}

/** Bilingual copy for the whole control library — self-contained, no global i18n keys. */
const FORMS_COPY_AR = {
    placeholder: 'اختر…',
    searchPlaceholder: 'ابحث…',
    noResults: 'لا توجد نتائج مطابقة',
    empty: 'لا توجد خيارات',
    loading: 'جارٍ التحميل…',
    error: 'تعذّر تحميل الخيارات',
    retry: 'إعادة المحاولة',
    clear: 'مسح الاختيار',
    clearAll: 'مسح الكل',
    selectAll: 'تحديد الكل',
    add: 'إضافة',
    addNew: 'إضافة خيار جديد',
    manage: 'إدارة الخيارات',
    remove: 'إزالة',
    selectedCount: 'محدد',
    maxReached: 'بلغت الحد الأقصى للاختيارات',
    moveUp: 'تحريك لأعلى',
    moveDown: 'تحريك لأسفل',
    expand: 'توسيع',
    collapse: 'طيّ',
    system: 'خيار نظامي',
    // OptionDrawer
    drawerTitle: 'إضافة خيار',
    labelAr: 'الاسم (عربي)',
    labelEn: 'الاسم (إنجليزي)',
    description: 'الوصف',
    color: 'اللون',
    icon: 'الأيقونة',
    parent: 'الخيار الأب',
    noParent: 'بدون أب',
    save: 'حفظ',
    cancel: 'إلغاء',
    saving: 'جارٍ الحفظ…',
    required: 'هذا الحقل مطلوب',
    // TagInput
    tagPlaceholder: 'أضف وسمًا ثم اضغط Enter',
    suggestions: 'اقتراحات',
}

/** The copy shape (string-valued) both locales share. */
export type FormsCopy = typeof FORMS_COPY_AR

export const FORMS_COPY: Record<Locale, FormsCopy> = {
  ar: FORMS_COPY_AR,
  en: {
    placeholder: 'Select…',
    searchPlaceholder: 'Search…',
    noResults: 'No matching results',
    empty: 'No options',
    loading: 'Loading…',
    error: 'Could not load options',
    retry: 'Retry',
    clear: 'Clear selection',
    clearAll: 'Clear all',
    selectAll: 'Select all',
    add: 'Add',
    addNew: 'Add a new option',
    manage: 'Manage options',
    remove: 'Remove',
    selectedCount: 'selected',
    maxReached: 'Maximum selections reached',
    moveUp: 'Move up',
    moveDown: 'Move down',
    expand: 'Expand',
    collapse: 'Collapse',
    system: 'System option',
    drawerTitle: 'Add option',
    labelAr: 'Name (Arabic)',
    labelEn: 'Name (English)',
    description: 'Description',
    color: 'Color',
    icon: 'Icon',
    parent: 'Parent option',
    noParent: 'No parent',
    save: 'Save',
    cancel: 'Cancel',
    saving: 'Saving…',
    required: 'This field is required',
    tagPlaceholder: 'Add a tag, then press Enter',
    suggestions: 'Suggestions',
  },
}
