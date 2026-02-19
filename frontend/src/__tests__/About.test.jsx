// @vitest-environment jsdom
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/preact'
import About from '../About'

describe('About', () => {
  it('renders the page heading', () => {
    render(<About />)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('How It Works')
  })

  it('renders all 6 numbered steps', () => {
    render(<About />)
    for (const n of ['01', '02', '03', '04', '05', '06']) {
      expect(screen.getByText(n)).toBeInTheDocument()
    }
  })

  it('renders step titles', () => {
    render(<About />)
    expect(screen.getByText('You type your secret')).toBeInTheDocument()
    expect(screen.getByText('Your browser generates a random salt')).toBeInTheDocument()
    expect(screen.getByText('Your passphrase is stretched with PBKDF2')).toBeInTheDocument()
    expect(screen.getByText('Your secret is encrypted with AES-GCM-256')).toBeInTheDocument()
    expect(screen.getByText('Only ciphertext reaches the server')).toBeInTheDocument()
    expect(screen.getByText('Retrieval is passphrase-gated and decrypted locally')).toBeInTheDocument()
  })

  it('renders the Security Guarantees section', () => {
    render(<About />)
    expect(screen.getByRole('heading', { level: 2, name: 'Security Guarantees' })).toBeInTheDocument()
  })

  it('renders all four security guarantee items', () => {
    render(<About />)
    expect(screen.getByText(/Your secret never leaves your browser in plaintext/)).toBeInTheDocument()
    expect(screen.getByText(/Your passphrase never leaves your browser/)).toBeInTheDocument()
    expect(screen.getByText(/The server cannot read your secrets/)).toBeInTheDocument()
    expect(screen.getByText(/Decryption happens locally on retrieval/)).toBeInTheDocument()
  })

  it('renders a link to the GitHub repository', () => {
    render(<About />)
    const link = screen.getByRole('link', { name: /View on GitHub/i })
    expect(link).toHaveAttribute('href', 'https://github.com/DisplaceTech/swordfish')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
  })
})
