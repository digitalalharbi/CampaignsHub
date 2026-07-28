import type { Locale } from '@/stores/ui'
import type { TaxonomyDefinition, TaxonomyOption, TaxonomyScope } from './taxonomyApi'

/**
 * Self-contained bilingual copy for the Taxonomy Manager (Arabic-first). Mirrors the form-control
 * library's approach — no global i18n keys, so the page ships as one unit.
 */
const AR = {
  title: 'التصنيفات والخيارات',
  subtitle: 'أدِر حقول التصنيف وقيمها عبر المنصة — الترتيب، الترجمة، الدمج، وإعادة الإسناد.',
  searchDefinitions: 'ابحث في التصنيفات…',
  noDefinitions: 'لا توجد تصنيفات',
  noDefinitionsHint: 'لم تُعرَّف أي حقول تصنيف بعد.',
  selectDefinition: 'اختر تصنيفًا لعرض خياراته',
  selectDefinitionHint: 'اختر حقل تصنيف من القائمة لإدارة قيمه.',
  loading: 'جارٍ التحميل…',
  loadError: 'تعذّر تحميل البيانات',
  retry: 'إعادة المحاولة',
  noOptions: 'لا توجد خيارات',
  noOptionsHint: 'لم تُضَف أي خيارات لهذا التصنيف بعد.',
  addOption: 'إضافة خيار',
  addChild: 'إضافة خيار فرعي',
  edit: 'تعديل',
  merge: 'دمج',
  reassign: 'إعادة إسناد',
  deactivate: 'تعطيل',
  activate: 'تفعيل',
  setDefault: 'تعيين كافتراضي',
  moveUp: 'تحريك لأعلى',
  moveDown: 'تحريك لأسفل',
  actions: 'إجراءات',
  system: 'نظامي',
  default: 'افتراضي',
  inactive: 'مُعطّل',
  usage: 'الاستخدام',
  usageCount: 'سجلّ',
  readOnly: 'وضع القراءة فقط — لا تملك صلاحية إدارة التصنيفات.',
  // scopes
  scope_platform: 'المنصة',
  scope_tenant: 'المستأجر',
  scope_client: 'العميل',
  scope_project: 'المشروع',
  scope_module: 'الوحدة',
  // field types
  type_single: 'اختيار مفرد',
  type_multi: 'اختيار متعدد',
  type_hierarchical: 'هرمي',
  // edit drawer
  editTitle: 'تعديل الخيار',
  key: 'المفتاح',
  keyLocked: 'المفتاح غير قابل للتعديل',
  labelAr: 'الاسم (عربي)',
  labelEn: 'الاسم (إنجليزي)',
  description: 'الوصف',
  color: 'اللون',
  icon: 'الأيقونة',
  active: 'مُفعّل',
  systemNotice: 'خيار نظامي: يمكن تعديل الاسم والوصف واللون والأيقونة والحالة فقط.',
  save: 'حفظ',
  saving: 'جارٍ الحفظ…',
  cancel: 'إلغاء',
  required: 'الاسم مطلوب',
  // merge / reassign dialog
  mergeTitle: 'دمج الخيار',
  reassignTitle: 'إعادة إسناد الخيار',
  mergeInto: 'ادمج في',
  reassignInto: 'أعد الإسناد إلى',
  pickTarget: 'اختر الخيار الهدف…',
  mergeExplain: 'ستُنقل السجلات المرتبطة إلى الهدف ثم يُعطَّل هذا الخيار.',
  reassignExplain: 'ستُنقل السجلات المرتبطة إلى الهدف دون تعطيل هذا الخيار.',
  usageSummary: 'عدد السجلات المرتبطة:',
  confirm: 'تأكيد',
  processing: 'جارٍ التنفيذ…',
  noTargets: 'لا توجد خيارات أخرى متاحة كهدف.',
  conflictTitle: 'لا يمكن الحذف',
  conflictBody: 'هذا الخيار قيد الاستخدام أو نظامي. استخدم الدمج أو إعادة الإسناد أو التعطيل بدلًا من الحذف.',
  close: 'إغلاق',
  systemLockRow: 'خيار نظامي — الإجراءات محدودة',
}

type Copy = typeof AR

const EN: Copy = {
  title: 'Taxonomies & Options',
  subtitle: 'Manage classification fields and their values across the platform — order, translation, merge, and reassign.',
  searchDefinitions: 'Search taxonomies…',
  noDefinitions: 'No taxonomies',
  noDefinitionsHint: 'No classification fields have been defined yet.',
  selectDefinition: 'Select a taxonomy to view its options',
  selectDefinitionHint: 'Pick a classification field from the list to manage its values.',
  loading: 'Loading…',
  loadError: 'Could not load data',
  retry: 'Retry',
  noOptions: 'No options',
  noOptionsHint: 'No options have been added to this taxonomy yet.',
  addOption: 'Add option',
  addChild: 'Add child option',
  edit: 'Edit',
  merge: 'Merge',
  reassign: 'Reassign',
  deactivate: 'Deactivate',
  activate: 'Activate',
  setDefault: 'Set as default',
  moveUp: 'Move up',
  moveDown: 'Move down',
  actions: 'Actions',
  system: 'System',
  default: 'Default',
  inactive: 'Inactive',
  usage: 'Usage',
  usageCount: 'records',
  readOnly: 'Read-only — you do not have permission to manage taxonomies.',
  scope_platform: 'Platform',
  scope_tenant: 'Tenant',
  scope_client: 'Client',
  scope_project: 'Project',
  scope_module: 'Module',
  type_single: 'Single select',
  type_multi: 'Multi select',
  type_hierarchical: 'Hierarchical',
  editTitle: 'Edit option',
  key: 'Key',
  keyLocked: 'The key cannot be changed',
  labelAr: 'Name (Arabic)',
  labelEn: 'Name (English)',
  description: 'Description',
  color: 'Color',
  icon: 'Icon',
  active: 'Active',
  systemNotice: 'System option: only name, description, color, icon and status can be edited.',
  save: 'Save',
  saving: 'Saving…',
  cancel: 'Cancel',
  required: 'A name is required',
  mergeTitle: 'Merge option',
  reassignTitle: 'Reassign option',
  mergeInto: 'Merge into',
  reassignInto: 'Reassign to',
  pickTarget: 'Pick a target option…',
  mergeExplain: 'Bound records move to the target, then this option is deactivated.',
  reassignExplain: 'Bound records move to the target without deactivating this option.',
  usageSummary: 'Bound records:',
  confirm: 'Confirm',
  processing: 'Working…',
  noTargets: 'No other options are available as a target.',
  conflictTitle: 'Cannot delete',
  conflictBody: 'This option is in use or is a system option. Use merge, reassign or deactivate instead of deleting.',
  close: 'Close',
  systemLockRow: 'System option — limited actions',
}

export const TAX_COPY: Record<Locale, Copy> = { ar: AR, en: EN }

/** Resolve a definition's label for the active locale (Arabic-first). */
export function definitionLabel(def: TaxonomyDefinition, locale: Locale): string {
  const primary = locale === 'ar' ? def.label_ar : def.label_en
  return primary || def.label_en || def.label_ar || def.key
}

/** Resolve an option's label for the active locale (Arabic-first). */
export function taxOptionLabel(opt: TaxonomyOption, locale: Locale): string {
  const primary = locale === 'ar' ? opt.label_ar : opt.label_en
  return primary || opt.label_en || opt.label_ar || opt.key
}

export function scopeLabel(scope: TaxonomyScope, c: Copy): string {
  return c[`scope_${scope}` as const]
}

export function fieldTypeLabel(type: TaxonomyDefinition['field_type'], c: Copy): string {
  return c[`type_${type}` as const]
}
