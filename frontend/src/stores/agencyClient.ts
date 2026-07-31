import { create } from 'zustand'
import { persist } from 'zustand/middleware'

/**
 * Which client the agency operator is currently working inside (AGENCY-006).
 *
 * Separate from `useProject` on purpose: the project store is the shared contract every
 * project-scoped page reads, in either portal, and it must not grow a second dimension that only
 * one portal understands. This holds the extra step the agency has and the advertiser does not.
 *
 * **Persisted, and therefore never trusted.** A stored id survives a permission change, a client
 * being archived, and someone editing local storage by hand. `AgencyScopeSwitcher` re-validates it
 * against the server's authorised list on every mount and clears it when it is not there, and every
 * endpoint underneath applies the membership's client ceiling regardless. What is kept here is a
 * convenience, not a claim.
 */
interface AgencyClientState {
  currentClientId: string | null
  setCurrentClientId: (id: string | null) => void
}

export const useAgencyClient = create<AgencyClientState>()(
  persist(
    (set) => ({
      currentClientId: null,
      setCurrentClientId: (id) => set({ currentClientId: id }),
    }),
    { name: 'campaign-hub-agency-client' },
  ),
)
