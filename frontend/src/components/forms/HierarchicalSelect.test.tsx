import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { HierarchicalSelect } from './HierarchicalSelect'
import type { Option } from './types'

const TREE: Option[] = [
  { value: 'ads', label_en: 'Advertising', label_ar: 'الإعلانات' },
  { value: 'ads.new', label_en: 'New campaign', label_ar: 'حملة جديدة', parentValue: 'ads' },
  { value: 'ads.opt', label_en: 'Optimization', label_ar: 'تحسين', parentValue: 'ads' },
  { value: 'analytics', label_en: 'Analytics', label_ar: 'تحليلات' },
]

function Harness(props: Partial<React.ComponentProps<typeof HierarchicalSelect>> = {}) {
  const [value, setValue] = useState<string | null>(props.value ?? null)
  return <HierarchicalSelect label="Category" options={TREE} {...props} value={value} onChange={setValue} />
}

describe('HierarchicalSelect', () => {
  it('expands a branch and selects a child', () => {
    renderWithProviders(<Harness />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Category' }))

    // Children are hidden until the parent is expanded.
    expect(screen.queryByRole('treeitem', { name: /New campaign/ })).not.toBeInTheDocument()
    const parent = screen.getByRole('treeitem', { name: /Advertising/ })
    fireEvent.mouseDown(within(parent).getByRole('button', { name: 'Expand' })) // expand via chevron

    const child = screen.getByRole('treeitem', { name: /New campaign/ })
    fireEvent.mouseDown(child)
    // Selecting the child closes the panel and shows its label on the trigger.
    expect(screen.getByRole('combobox', { name: 'Category' })).toHaveTextContent('New campaign')
  })

  it('leaf-only: a branch cannot be selected, only expanded', () => {
    renderWithProviders(<Harness leafOnly />, { locale: 'en' })
    const trigger = screen.getByRole('combobox', { name: 'Category' })
    fireEvent.click(trigger)
    const parent = screen.getByRole('treeitem', { name: /Advertising/ })
    fireEvent.mouseDown(parent)
    // Expansion happened (child visible) but the branch was NOT selected (still placeholder, panel open).
    expect(screen.getByRole('treeitem', { name: /Optimization/ })).toBeInTheDocument()
    expect(trigger).toHaveTextContent('Select…')
  })

  it('search reveals matching descendants across collapsed branches', () => {
    renderWithProviders(<Harness searchable />, { locale: 'en' })
    fireEvent.click(screen.getByRole('combobox', { name: 'Category' }))
    fireEvent.change(screen.getByPlaceholderText('Search…'), { target: { value: 'Optim' } })
    const tree = screen.getByRole('tree')
    expect(within(tree).getByRole('treeitem', { name: /Optimization/ })).toBeInTheDocument()
    expect(within(tree).queryByRole('treeitem', { name: /Analytics/ })).not.toBeInTheDocument()
  })
})
