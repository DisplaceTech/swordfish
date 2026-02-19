import { useState } from 'preact/hooks'
import { encrypt } from './crypto'
import { createSecret } from './api'

const TTL_OPTIONS = [
  { label: '1 hour',   value: 3600 },
  { label: '6 hours',  value: 21600 },
  { label: '24 hours', value: 86400 },
  { label: '3 days',   value: 259200 },
  { label: '7 days',   value: 604800 },
]

const VIEW_OPTIONS = [
  { label: '1 view',       value: 1 },
  { label: '3 views',      value: 3 },
  { label: '5 views',      value: 5 },
  { label: '10 views',     value: 10 },
  { label: 'Unlimited',    value: 0 },
]

const SELECT_CLASS =
  'w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 ' +
  'focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'

export default function Create() {
  const [secret,   setSecret]   = useState('')
  const [password, setPassword] = useState('')
  const [ttl,      setTtl]      = useState(86400)
  const [maxViews, setMaxViews] = useState(1)
  const [loading,  setLoading]  = useState(false)
  const [error,    setError]    = useState('')
  const [result,   setResult]   = useState(null)
  const [copied,   setCopied]   = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')

    if (!secret.trim()) {
      setError('Secret cannot be empty.')
      return
    }
    if (!password.trim()) {
      setError('Passphrase cannot be empty.')
      return
    }

    setLoading(true)
    try {
      const encrypted_secret = await encrypt(secret, password)
      const data = await createSecret({ encrypted_secret, ttl, max_views: maxViews })
      const link = `${window.location.origin}/secret/${data.id}#${encodeURIComponent(password)}`
      setResult({ id: data.id, link })
    } catch (err) {
      setError(err.message || 'Something went wrong. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(result.link)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      setError('Failed to copy to clipboard.')
    }
  }

  function handleReset() {
    setSecret('')
    setPassword('')
    setTtl(86400)
    setMaxViews(1)
    setError('')
    setResult(null)
    setCopied(false)
  }

  if (result) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-12">
        <h1 className="mb-2 text-4xl font-bold text-gray-100">Secret Created</h1>
        <p className="mb-8 text-lg text-gray-400">
          Your secret has been encrypted and stored. Share the link below — the passphrase
          is embedded in the URL fragment and never sent to the server.
        </p>

        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
          <label className="mb-2 block text-sm font-medium text-gray-300">
            Shareable link
          </label>
          <div className="flex gap-2">
            <input
              type="text"
              readOnly
              value={result.link}
              className="min-w-0 flex-1 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:outline-none"
              onFocus={e => e.target.select()}
            />
            <button
              type="button"
              onClick={handleCopy}
              className={`shrink-0 rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
                copied
                  ? 'bg-green-700 text-green-100'
                  : 'bg-indigo-600 text-white hover:bg-indigo-500'
              }`}
            >
              {copied ? 'Copied!' : 'Copy'}
            </button>
          </div>
          <p className="mt-3 text-xs text-gray-500">
            Secret ID: <span className="font-mono text-gray-400">{result.id}</span>
          </p>
        </div>

        <button
          type="button"
          onClick={handleReset}
          className="mt-6 text-sm text-indigo-400 hover:text-indigo-300 transition-colors"
        >
          Create another secret
        </button>
      </main>
    )
  }

  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-4xl font-bold text-gray-100">Create a Secret</h1>
      <p className="mb-8 text-lg text-gray-400">
        Your secret is encrypted in your browser before it ever reaches the server.
        Share the link — only someone with the passphrase can read it.
      </p>

      <form onSubmit={handleSubmit} noValidate>
        <div className="flex flex-col gap-5 rounded-xl border border-gray-800 bg-gray-900 p-6">

          <div>
            <label htmlFor="secret" className="mb-1.5 block text-sm font-medium text-gray-300">
              Secret
            </label>
            <textarea
              id="secret"
              rows={5}
              placeholder="Enter the secret you want to share…"
              value={secret}
              onInput={e => setSecret(e.target.value)}
              disabled={loading}
              className="w-full resize-y rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-50"
            />
          </div>

          <div>
            <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-300">
              Passphrase
            </label>
            <input
              id="password"
              type="password"
              placeholder="Choose a passphrase to encrypt your secret"
              value={password}
              onInput={e => setPassword(e.target.value)}
              disabled={loading}
              className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-50"
            />
            <p className="mt-1 text-xs text-gray-500">
              The passphrase is embedded in the shareable link and never sent to the server.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="ttl" className="mb-1.5 block text-sm font-medium text-gray-300">
                Expires after
              </label>
              <select
                id="ttl"
                value={ttl}
                onChange={e => setTtl(Number(e.target.value))}
                disabled={loading}
                className={SELECT_CLASS + ' disabled:opacity-50'}
              >
                {TTL_OPTIONS.map(opt => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="maxViews" className="mb-1.5 block text-sm font-medium text-gray-300">
                View limit
              </label>
              <select
                id="maxViews"
                value={maxViews}
                onChange={e => setMaxViews(Number(e.target.value))}
                disabled={loading}
                className={SELECT_CLASS + ' disabled:opacity-50'}
              >
                {VIEW_OPTIONS.map(opt => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
          </div>

          {error && (
            <p role="alert" className="rounded-lg border border-red-800 bg-red-950 px-4 py-2.5 text-sm text-red-300">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={loading}
            className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {loading ? (
              <>
                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                Encrypting…
              </>
            ) : (
              'Encrypt & Create Secret'
            )}
          </button>
        </div>
      </form>
    </main>
  )
}
