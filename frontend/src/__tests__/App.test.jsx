import { render, screen } from '@testing-library/react'
import App from '../App'

describe('App', () => {
  it('renders the Swordfish heading', () => {
    render(<App />)
    expect(screen.getByRole('heading', { name: /swordfish/i })).toBeInTheDocument()
  })
})
