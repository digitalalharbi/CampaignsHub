import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { OtpField } from './OtpField'

/**
 * LOGIN-OTP-001 — the three things that actually break an OTP input.
 *
 * Not «does it render six boxes». Paste, backspace on an empty box, and digits that are not Latin
 * are what turn a working code into a sign-in that will not go through, and all three are invisible
 * until somebody hits them with a real code in their hand.
 */
function Harness({ onComplete }: { onComplete?: (code: string) => void }) {
  const [value, setValue] = useState('')

  return <OtpField label="Verification code" value={value} onChange={setValue} onComplete={onComplete} />
}

describe('OtpField', () => {
  it('renders one box per digit', () => {
    render(<Harness />)
    for (let i = 0; i < 6; i++) expect(screen.getByTestId(`login-otp-${i}`)).toBeInTheDocument()
  })

  /** Most people paste the whole code. A per-box `maxLength=1` would throw away five characters. */
  it('takes a pasted code and distributes it', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), {
      clipboardData: { getData: () => '123456' },
    })

    expect(screen.getByTestId('login-otp-0')).toHaveValue('1')
    expect(screen.getByTestId('login-otp-5')).toHaveValue('6')
  })

  /** Pasting into a later box still fills from the start — the code is one number, not a fragment. */
  it('a paste into any box fills the whole code', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-3'), {
      clipboardData: { getData: () => '987654' },
    })

    expect(screen.getByTestId('login-otp-0')).toHaveValue('9')
    expect(screen.getByTestId('login-otp-5')).toHaveValue('4')
  })

  /**
   * An Arabic keyboard produces `٠١٢٣٤٥٦٧٨٩`, which no comparison on the server will match.
   *
   * Folding them here is the same rule the platform applies everywhere else: Latin digits, always.
   */
  it('folds Arabic-Indic digits to Latin', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), {
      clipboardData: { getData: () => '٤٢٤٢٤٢' },
    })

    expect(screen.getByTestId('login-otp-0')).toHaveValue('4')
    expect(screen.getByTestId('login-otp-1')).toHaveValue('2')
  })

  /** Spaces and dashes in a pasted code are not digits and are simply not code. */
  it('ignores anything that is not a digit', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), {
      clipboardData: { getData: () => '12-34 56' },
    })

    expect(screen.getByTestId('login-otp-5')).toHaveValue('6')
  })

  /** Backspace in an empty box clears the previous one — otherwise correcting means clicking back. */
  it('backspace in an empty box clears the digit before it', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '1234' } })
    fireEvent.keyDown(screen.getByTestId('login-otp-4'), { key: 'Backspace' })

    expect(screen.getByTestId('login-otp-3')).toHaveValue('')
  })

  it('backspace in a filled box clears that box', () => {
    render(<Harness />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '123456' } })
    fireEvent.keyDown(screen.getByTestId('login-otp-2'), { key: 'Backspace' })

    expect(screen.getByTestId('login-otp-2')).toHaveValue('4')
  })

  /**
   * The sixth digit submits, and it carries what was typed.
   *
   * Passed as an argument rather than read from state: the state holding it has not been committed
   * at the moment this fires, so a caller reading `value` would submit five digits.
   */
  it('reports the complete code the moment the sixth digit lands', () => {
    const onComplete = vi.fn()
    render(<Harness onComplete={onComplete} />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '424242' } })

    expect(onComplete).toHaveBeenCalledWith('424242')
  })

  it('does not report an incomplete code', () => {
    const onComplete = vi.fn()
    render(<Harness onComplete={onComplete} />)

    fireEvent.paste(screen.getByTestId('login-otp-0'), { clipboardData: { getData: () => '4242' } })

    expect(onComplete).not.toHaveBeenCalled()
  })

  /** A code is a number read left to right, in Arabic as in English. */
  it('lays the boxes out left to right in both languages', () => {
    render(<Harness />)
    expect(screen.getByTestId('login-otp')).toHaveAttribute('dir', 'ltr')
  })
})
