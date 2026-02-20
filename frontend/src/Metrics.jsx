import { useState, useEffect } from 'preact/hooks'
import { fetchMetrics } from './api'

function formatBytes(bytes) {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const val = bytes / Math.pow(1024, i)
  return `${val < 10 ? val.toFixed(1) : Math.round(val)} ${units[i]}`
}

function formatNumber(n) {
  return n.toLocaleString()
}

function aggregateDaily(hours) {
  const byDay = {}
  for (const h of hours) {
    const day = h.hour.split(':')[0]
    if (!byDay[day]) byDay[day] = { label: day, created: 0, retrieved: 0, bytes_stored: 0, bytes_retrieved: 0 }
    byDay[day].created += h.created
    byDay[day].retrieved += h.retrieved
    byDay[day].bytes_stored += h.bytes_stored
    byDay[day].bytes_retrieved += h.bytes_retrieved
  }
  return Object.values(byDay)
}

function filterLast24h(hours) {
  const cutoff = new Date()
  cutoff.setHours(cutoff.getHours() - 24)
  const cutoffStr = `${cutoff.getFullYear()}-${String(cutoff.getMonth() + 1).padStart(2, '0')}-${String(cutoff.getDate()).padStart(2, '0')}:${String(cutoff.getHours()).padStart(2, '0')}`
  return hours.filter(h => h.hour >= cutoffStr)
}

function SummaryCard({ label, value, sub }) {
  return (
    <div className="rounded-lg border border-gray-700 bg-gray-900 p-5">
      <p className="text-sm text-gray-400">{label}</p>
      <p className="mt-1 text-2xl font-bold text-gray-100">{value}</p>
      {sub && <p className="mt-0.5 text-xs text-gray-500">{sub}</p>}
    </div>
  )
}

function BarChart({ data, dataKey, label, xLabel }) {
  const [hoverIdx, setHoverIdx] = useState(null)

  if (!data.length) {
    return (
      <div className="rounded-lg border border-gray-700 bg-gray-900 p-5">
        <h3 className="mb-3 text-sm font-semibold text-gray-300">{label}</h3>
        <p className="text-sm text-gray-500">No data for this period.</p>
      </div>
    )
  }

  const values = data.map(d => d[dataKey])
  const max = Math.max(...values, 1)

  const chartW = 600
  const chartH = 200
  const padL = 50
  const padR = 10
  const padT = 10
  const padB = 40
  const innerW = chartW - padL - padR
  const innerH = chartH - padT - padB

  const barGap = 2
  const barW = Math.max(1, (innerW - barGap * (data.length - 1)) / data.length)

  const gridLines = 4
  const step = max / gridLines

  return (
    <div className="rounded-lg border border-gray-700 bg-gray-900 p-5">
      <h3 className="mb-3 text-sm font-semibold text-gray-300">{label}</h3>
      <svg
        viewBox={`0 0 ${chartW} ${chartH}`}
        width="100%"
        role="img"
        aria-label={label}
        className="overflow-visible"
      >
        {Array.from({ length: gridLines + 1 }, (_, i) => {
          const y = padT + innerH - (i * innerH) / gridLines
          const val = Math.round(i * step)
          return (
            <g key={i}>
              <line x1={padL} y1={y} x2={chartW - padR} y2={y} stroke="#374151" strokeWidth="0.5" />
              <text x={padL - 6} y={y + 3} textAnchor="end" fill="#9CA3AF" fontSize="9">{formatNumber(val)}</text>
            </g>
          )
        })}

        {data.map((d, i) => {
          const val = d[dataKey]
          const barH = (val / max) * innerH
          const x = padL + i * (barW + barGap)
          const y = padT + innerH - barH
          const isHover = hoverIdx === i
          return (
            <g
              key={i}
              onMouseEnter={() => setHoverIdx(i)}
              onMouseLeave={() => setHoverIdx(null)}
              onFocus={() => setHoverIdx(i)}
              onBlur={() => setHoverIdx(null)}
              tabIndex={0}
              role="graphics-symbol"
              aria-label={`${xLabel(d)}: ${formatNumber(val)}`}
            >
              <rect
                x={x}
                y={y}
                width={barW}
                height={Math.max(barH, 0.5)}
                rx="1"
                fill={isHover ? '#818CF8' : '#6366F1'}
                className="transition-colors"
              />
              {isHover && (
                <text
                  x={x + barW / 2}
                  y={y - 5}
                  textAnchor="middle"
                  fill="#E0E7FF"
                  fontSize="9"
                  fontWeight="600"
                >
                  {formatNumber(val)}
                </text>
              )}
            </g>
          )
        })}

        {data.map((d, i) => {
          const x = padL + i * (barW + barGap) + barW / 2
          const showLabel = data.length <= 14 || i % Math.ceil(data.length / 12) === 0
          if (!showLabel) return null
          return (
            <text
              key={`label-${i}`}
              x={x}
              y={chartH - 5}
              textAnchor="middle"
              fill="#9CA3AF"
              fontSize="8"
              transform={`rotate(-30, ${x}, ${chartH - 5})`}
            >
              {xLabel(d)}
            </text>
          )
        })}
      </svg>
    </div>
  )
}

export default function Metrics() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [range, setRange] = useState('7d')

  useEffect(() => {
    fetchMetrics()
      .then(d => setData(d))
      .catch(err => setError(err.message))
  }, [])

  if (error) {
    return (
      <main className="mx-auto max-w-5xl px-4 py-12">
        <h1 className="mb-6 text-3xl font-bold text-gray-100">Metrics</h1>
        <p role="alert" className="rounded-lg border border-red-800 bg-red-950 px-4 py-3 text-sm text-red-300">
          {error}
        </p>
      </main>
    )
  }

  if (!data) {
    return (
      <main className="mx-auto max-w-5xl px-4 py-12">
        <h1 className="mb-6 text-3xl font-bold text-gray-100">Metrics</h1>
        <p className="text-gray-400" aria-live="polite">Loading metrics...</p>
      </main>
    )
  }

  const allHours = data.hours
  const displayData = range === '24h' ? filterLast24h(allHours) : allHours
  const chartData = range === '7d' ? aggregateDaily(displayData) : displayData

  const totals = allHours.reduce(
    (acc, h) => ({
      created: acc.created + h.created,
      retrieved: acc.retrieved + h.retrieved,
      bytes_stored: acc.bytes_stored + h.bytes_stored,
      bytes_retrieved: acc.bytes_retrieved + h.bytes_retrieved,
    }),
    { created: 0, retrieved: 0, bytes_stored: 0, bytes_retrieved: 0 }
  )

  const xLabel = range === '7d'
    ? d => d.label.slice(5)
    : d => d.hour.split(':')[1] + ':00'

  return (
    <main className="mx-auto max-w-5xl px-4 py-12">
      <div className="mb-8 flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-3xl font-bold text-gray-100">Metrics</h1>
        <div className="flex rounded-lg border border-gray-700 overflow-hidden" role="radiogroup" aria-label="Time range">
          <button
            type="button"
            role="radio"
            aria-checked={range === '24h'}
            onClick={() => setRange('24h')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
              range === '24h'
                ? 'bg-indigo-600 text-white'
                : 'bg-gray-900 text-gray-400 hover:text-gray-100'
            }`}
          >
            Last 24 hours
          </button>
          <button
            type="button"
            role="radio"
            aria-checked={range === '7d'}
            onClick={() => setRange('7d')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
              range === '7d'
                ? 'bg-indigo-600 text-white'
                : 'bg-gray-900 text-gray-400 hover:text-gray-100'
            }`}
          >
            Last 7 days
          </button>
        </div>
      </div>

      <div className="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4" data-testid="summary-cards">
        <SummaryCard label="Secrets Created" value={formatNumber(totals.created)} />
        <SummaryCard label="Secrets Retrieved" value={formatNumber(totals.retrieved)} />
        <SummaryCard label="Data Stored" value={formatBytes(totals.bytes_stored)} />
        <SummaryCard label="Data Retrieved" value={formatBytes(totals.bytes_retrieved)} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <BarChart
          data={chartData}
          dataKey="created"
          label="Secrets Created"
          xLabel={xLabel}
        />
        <BarChart
          data={chartData}
          dataKey="retrieved"
          label="Secrets Retrieved"
          xLabel={xLabel}
        />
      </div>
    </main>
  )
}
