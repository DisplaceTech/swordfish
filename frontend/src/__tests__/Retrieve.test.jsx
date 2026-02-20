// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/preact'
import Retrieve from '../Retrieve'

vi.mock('../crypto', () => ({
  getVerifier: vi.fn(),
  decrypt: vi.fn(),
}))

vi.mock('../api', () => ({
  retrieveSecret: vi.fn(),
}))

import { getVerifier, decrypt } from '../crypto'
import { retrieveSecret } from '../api'

const MOCK_RESPONSE = {
  encrypted_secret: 'aabbcc',
  views_remaining: 2,
  expires_at: 9999999999,
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('Retrieve — initial render', () => {
  it('renders the page heading', () => {
    render(<Retrieve />)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Retrieve a Secret')
  })

  it('renders the secret ID input', () => {
    render(<Retrieve />)
    expect(screen.getByLabelText('Secret ID')).toBeInTheDocument()
  })

  it('renders the passphrase input', () => {
    render(<Retrieve />)
    expect(screen.getByLabelText('Passphrase')).toBeInTheDocument()
  })

  it('renders the submit button', () => {
    render(<Retrieve />)
    expect(screen.getByRole('button', { name: 'Retrieve Secret' })).toBeInTheDocument()
  })

  it('pre-fills the secret ID from the id prop', () => {
    render(<Retrieve id="abc123" />)
    expect(screen.getByLabelText('Secret ID')).toHaveValue('abc123')
  })

  it('leaves secret ID empty when no id prop is given', () => {
    render(<Retrieve />)
    expect(screen.getByLabelText('Secret ID')).toHaveValue('')
  })
})

describe('Retrieve — submission flow', () => {
  it('shows loading state while retrieving', async () => {
    getVerifier.mockReturnValue(new Promise(() => {}))
    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))
    expect(await screen.findByText('Decrypting…')).toBeInTheDocument()
  })

  it('disables submit button during loading', async () => {
    getVerifier.mockReturnValue(new Promise(() => {}))
    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))
    await screen.findByText('Decrypting…')
    expect(screen.getByRole('button', { name: 'Decrypting…' })).toBeDisabled()
  })

  it('shows decrypted plaintext on success', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue(MOCK_RESPONSE)
    decrypt.mockResolvedValue('the secret message')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('Your Secret')
    expect(screen.getByText('the secret message')).toBeInTheDocument()
  })

  it('shows views remaining on success', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue({ ...MOCK_RESPONSE, views_remaining: 3 })
    decrypt.mockResolvedValue('the secret message')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
    expect(screen.getByText(/3 views remaining/)).toBeInTheDocument()
  })

  it('shows singular "view" when 1 view remains', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue({ ...MOCK_RESPONSE, views_remaining: 1 })
    decrypt.mockResolvedValue('msg')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
    expect(screen.getByText(/1 view remaining/)).toBeInTheDocument()
  })

  it('shows unlimited views message when views_remaining is null', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue({ ...MOCK_RESPONSE, views_remaining: null })
    decrypt.mockResolvedValue('msg')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
    expect(screen.getByText(/Unlimited views/)).toBeInTheDocument()
  })

  it('shows deletion notice when 0 views remain', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue({ ...MOCK_RESPONSE, views_remaining: 0 })
    decrypt.mockResolvedValue('msg')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
    expect(screen.getByText(/No views remaining/)).toBeInTheDocument()
  })

  it('passes verifier and trimmed id to retrieveSecret', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue(MOCK_RESPONSE)
    decrypt.mockResolvedValue('msg')

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: '  abc123  ' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
    expect(retrieveSecret).toHaveBeenCalledWith('abc123', 'verifier-hex')
  })
})

describe('Retrieve — error states', () => {
  it('shows friendly error for "not found" response', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockRejectedValue(new Error('not found'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Secret not found. It may have expired or been fully viewed.'
    )
  })

  it('shows friendly error for "expired" response', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockRejectedValue(new Error('expired'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('This secret has expired.')
  })

  it('shows friendly error for "views exceeded" response', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockRejectedValue(new Error('views exceeded'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('This secret has no views remaining.')
  })

  it('shows passphrase error when decrypt fails', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue(MOCK_RESPONSE)
    decrypt.mockRejectedValue(new Error('The operation failed'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'wrongpass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Incorrect passphrase. Please try again.')
  })

  it('shows raw error message for unknown errors', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockRejectedValue(new Error('Something unexpected'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Something unexpected')
  })

  it('re-enables submit button after error', async () => {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockRejectedValue(new Error('not found'))

    render(<Retrieve />)
    fireEvent.input(screen.getByLabelText('Secret ID'), { target: { value: 'abc123' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))

    await screen.findByRole('alert')
    expect(screen.getByRole('button', { name: 'Retrieve Secret' })).not.toBeDisabled()
  })
})

describe('Retrieve — success state interactions', () => {
  async function renderSuccess(overrides = {}) {
    getVerifier.mockResolvedValue('verifier-hex')
    retrieveSecret.mockResolvedValue({ ...MOCK_RESPONSE, ...overrides })
    decrypt.mockResolvedValue('the secret message')

    render(<Retrieve id="abc123" />)
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Retrieve Secret' }).closest('form'))
    await screen.findByRole('heading', { level: 1, name: 'Your Secret' })
  }

  it('"Retrieve another secret" resets to the form', async () => {
    await renderSuccess()
    fireEvent.click(screen.getByRole('button', { name: /Retrieve another secret/ }))
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Retrieve a Secret')
  })

  it('restores the original id prop after reset', async () => {
    await renderSuccess()
    fireEvent.click(screen.getByRole('button', { name: /Retrieve another secret/ }))
    expect(screen.getByLabelText('Secret ID')).toHaveValue('abc123')
  })

  it('shows expiry date when expires_at is present', async () => {
    await renderSuccess({ expires_at: 2000000000 })
    expect(screen.getByText(/Expires/)).toBeInTheDocument()
  })
})
