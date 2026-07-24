import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface ProjectState {
  currentProjectId: string | null
  setCurrentProjectId: (id: string | null) => void
}

export const useProject = create<ProjectState>()(
  persist(
    (set) => ({
      currentProjectId: null,
      setCurrentProjectId: (id) => set({ currentProjectId: id }),
    }),
    {
      name: 'campaign-hub-project-storage',
    }
  )
)
