// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/preact'
import Create from '../Create'

vi.mock('../crypto', () => ({
  encrypt: vi.fn(),
}))

vi.mock('../api', () => ({
  createSecret: vi.fn(),
}))

import { encrypt } from '../crypto'
import { createSecret } from '../api'

beforeEach(() => {
  vi.clearAllMocks()
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
    writable: true,
    configurable: true,
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('Create — initial render', () => {
  it('renders the page heading', () => {
    render(<Create />)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Create a Secret')
  })

  it('renders the secret textarea', () => {
    render(<Create />)
    expect(screen.getByLabelText('Secret')).toBeInTheDocument()
  })

  it('renders the passphrase input', () => {
    render(<Create />)
    expect(screen.getByLabelText('Passphrase')).toBeInTheDocument()
  })

  it('renders the TTL select with default 24 hours', () => {
    render(<Create />)
    const select = screen.getByLabelText('Expires after')
    expect(select).toHaveValue('86400')
  })

  it('renders the view limit select with default 1 view', () => {
    render(<Create />)
    const select = screen.getByLabelText('View limit')
    expect(select).toHaveValue('1')
  })

  it('renders the submit button', () => {
    render(<Create />)
    expect(screen.getByRole('button', { name: 'Encrypt & Create Secret' })).toBeInTheDocument()
  })
})

describe('Create — validation', () => {
  it('shows an error when secret is empty on submit', async () => {
    render(<Create />)
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    expect(await screen.findByRole('alert')).toHaveTextContent('Secret cannot be empty.')
  })

  it('shows an error when passphrase is empty on submit', async () => {
    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    expect(await screen.findByRole('alert')).toHaveTextContent('Passphrase cannot be empty.')
  })

  it('does not call encrypt when validation fails', async () => {
    render(<Create />)
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    await screen.findByRole('alert')
    expect(encrypt).not.toHaveBeenCalled()
  })
})

describe('Create — submission flow', () => {
  it('shows loading state while encrypting', async () => {
    encrypt.mockReturnValue(new Promise(() => {}))
    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'passphrase' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    expect(await screen.findByText('Encrypting…')).toBeInTheDocument()
  })

  it('disables inputs during loading', async () => {
    encrypt.mockReturnValue(new Promise(() => {}))
    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'passphrase' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    await screen.findByText('Encrypting…')
    expect(screen.getByLabelText('Secret')).toBeDisabled()
    expect(screen.getByLabelText('Passphrase')).toBeDisabled()
  })

  it('shows success state with shareable link after submission', async () => {
    encrypt.mockResolvedValue('hex$verifier$payload')
    createSecret.mockResolvedValue({ id: 'abc123', expires_at: 9999999999, max_views: 1 })

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('Secret Created')
    const linkInput = screen.getByDisplayValue(/\/secret\/abc123#/)
    expect(linkInput).toBeInTheDocument()
    expect(screen.getByText(/abc123/)).toBeInTheDocument()
  })

  it('passes correct payload to createSecret', async () => {
    encrypt.mockResolvedValue('hex$verifier$payload')
    createSecret.mockResolvedValue({ id: 'abc123', expires_at: 9999999999, max_views: 1 })

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))

    await screen.findByRole('heading', { level: 1, name: 'Secret Created' })
    expect(createSecret).toHaveBeenCalledWith({
      encrypted_secret: 'hex$verifier$payload',
      ttl: 86400,
      max_views: 1,
    })
  })

  it('shows error message when API call fails', async () => {
    encrypt.mockResolvedValue('hex$verifier$payload')
    createSecret.mockRejectedValue(new Error('Service unavailable'))

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Service unavailable')
  })

  it('shows generic error when API throws without message', async () => {
    encrypt.mockResolvedValue('hex$verifier$payload')
    createSecret.mockRejectedValue(new Error())

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Something went wrong')
  })

  it('shows error when encrypt throws', async () => {
    encrypt.mockRejectedValue(new Error('Encryption failed'))

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))

    expect(await screen.findByRole('alert')).toHaveTextContent('Encryption failed')
  })
})

describe('Create — success state interactions', () => {
  async function renderSuccess() {
    encrypt.mockResolvedValue('hex$verifier$payload')
    createSecret.mockResolvedValue({ id: 'abc123', expires_at: 9999999999, max_views: 1 })

    render(<Create />)
    fireEvent.input(screen.getByLabelText('Secret'), { target: { value: 'my secret' } })
    fireEvent.input(screen.getByLabelText('Passphrase'), { target: { value: 'mypass' } })
    fireEvent.submit(screen.getByRole('button', { name: 'Encrypt & Create Secret' }).closest('form'))
    await screen.findByRole('heading', { level: 1, name: 'Secret Created' })
  }

  it('copy button writes link to clipboard', async () => {
    await renderSuccess()
    fireEvent.click(screen.getByRole('button', { name: 'Copy link to clipboard' }))
    await waitFor(() => {
      expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
        expect.stringContaining('/secret/abc123#')
      )
    })
  })

  it('copy button shows "Copied!" after click', async () => {
    vi.useFakeTimers()
    await renderSuccess()
    fireEvent.click(screen.getByRole('button', { name: 'Copy link to clipboard' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Link copied to clipboard' })).toBeInTheDocument())
  })

  it('"Create another secret" resets to the form', async () => {
    await renderSuccess()
    fireEvent.click(screen.getByRole('button', { name: 'Create another secret' }))
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Create a Secret')
    expect(screen.getByLabelText('Secret')).toHaveValue('')
  })


})

describe('Create — TTL and view limit selects', () => {
  it('updates TTL when a different option is selected', () => {
    render(<Create />)
    const select = screen.getByLabelText('Expires after')
    fireEvent.change(select, { target: { value: '3600' } })
    expect(select).toHaveValue('3600')
  })

  it('updates view limit when a different option is selected', () => {
    render(<Create />)
    const select = screen.getByLabelText('View limit')
    fireEvent.change(select, { target: { value: '5' } })
    expect(select).toHaveValue('5')
  })
})
