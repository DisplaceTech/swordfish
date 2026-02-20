// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/preact'
import Metrics from '../Metrics'

vi.mock('../api', () => ({
  fetchMetrics: vi.fn(),
}))

import { fetchMetrics } from '../api'

const MOCK_DATA = {
  hours: [
    { hour: '2026-02-18:10', created: 3, retrieved: 7, bytes_stored: 1024, bytes_retrieved: 4096 },
    { hour: '2026-02-18:14', created: 5, retrieved: 2, bytes_stored: 2048, bytes_retrieved: 512 },
    { hour: '2026-02-19:09', created: 1, retrieved: 0, bytes_stored: 256, bytes_retrieved: 0 },
  ],
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('Metrics — loading state', () => {
  it('shows loading text initially', () => {
    fetchMetrics.mockReturnValue(new Promise(() => {}))
    render(<Metrics />)
    expect(screen.getByText('Loading metrics...')).toBeInTheDocument()
  })

  it('renders the heading while loading', () => {
    fetchMetrics.mockReturnValue(new Promise(() => {}))
    render(<Metrics />)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Metrics')
  })
})

describe('Metrics — error state', () => {
  it('shows error message when API fails', async () => {
    fetchMetrics.mockRejectedValue(new Error('Failed to load metrics'))
    render(<Metrics />)
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load metrics')
  })

  it('still shows the heading on error', async () => {
    fetchMetrics.mockRejectedValue(new Error('Network error'))
    render(<Metrics />)
    await screen.findByRole('alert')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Metrics')
  })
})

describe('Metrics — data display', () => {
  it('renders summary cards with correct totals', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    const { container } = render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const cards = screen.getByTestId('summary-cards')
    const cardTexts = cards.textContent
    expect(cardTexts).toContain('Secrets Created')
    expect(cardTexts).toContain('Secrets Retrieved')
    expect(cardTexts).toContain('Data Stored')
    expect(cardTexts).toContain('Data Retrieved')
    expect(cardTexts).toContain('9')
    expect(cardTexts).toContain('3.3 KB')
  })

  it('renders two chart sections', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const charts = screen.getAllByRole('img')
    expect(charts.length).toBe(2)
  })

  it('renders chart labels for created and retrieved', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const imgs = screen.getAllByRole('img')
    expect(imgs[0]).toHaveAttribute('aria-label', 'Secrets Created')
    expect(imgs[1]).toHaveAttribute('aria-label', 'Secrets Retrieved')
  })

  it('shows "No data" message when hours array is empty', async () => {
    fetchMetrics.mockResolvedValue({ hours: [] })
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const messages = screen.getAllByText('No data for this period.')
    expect(messages.length).toBe(2)
  })
})

describe('Metrics — time range toggle', () => {
  it('defaults to 7-day view', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const btn7d = screen.getByRole('radio', { name: 'Last 7 days' })
    expect(btn7d).toHaveAttribute('aria-checked', 'true')
  })

  it('switches to 24-hour view on toggle click', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    const btn24h = screen.getByRole('radio', { name: 'Last 24 hours' })
    fireEvent.click(btn24h)
    expect(btn24h).toHaveAttribute('aria-checked', 'true')

    const btn7d = screen.getByRole('radio', { name: 'Last 7 days' })
    expect(btn7d).toHaveAttribute('aria-checked', 'false')
  })

  it('switches back to 7-day view', async () => {
    fetchMetrics.mockResolvedValue(MOCK_DATA)
    render(<Metrics />)

    await waitFor(() => {
      expect(screen.getByTestId('summary-cards')).toBeInTheDocument()
    })

    fireEvent.click(screen.getByRole('radio', { name: 'Last 24 hours' }))
    fireEvent.click(screen.getByRole('radio', { name: 'Last 7 days' }))

    expect(screen.getByRole('radio', { name: 'Last 7 days' })).toHaveAttribute('aria-checked', 'true')
  })
})
