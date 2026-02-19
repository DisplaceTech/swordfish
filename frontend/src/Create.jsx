import { useState } from 'preact/hooks'
import { encryptSecret } from './crypto.js'
import { createSecret } from './api.js'

const TTL_OPTIONS = [
  { label: '1 hour',  value: 3_600 },
  { label: '6 hours', value: 21_600 },
  { label: '24 hours', value: 86_400 },
  { label: '3 days',  value: 259_200 },
  { label: '7 days',  value: 604_800 },
]

const VIEW_OPTIONS = [
  { label: '1 view',       value: 1 },
  { label: '3 views',      value: 3 },
  { label: '5 views',      value: 5 },
  { label: '10 views',     value: 10 },
  { label: 'Unlimited',    value: 0 },
]

function FieldLabel({ htmlFor, children }) {
  return (
    <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-gray-300">
      {children}
    </label>
  )
}

function SelectField({ id, value, onChange, options }) {
  return (
    <select
      id={id}
      value={value}
      onChange={(e) => onChange(Number(e.target.value))}
      className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
    >
      {options.map((opt) => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  )
}

function ShareableLink({ secretId }) {
  const [copied, setCopied] = useState(false)
  const [copyError, setCopyError] = useState(false)
  const url = `${window.location.origin}/secret/${secretId}`

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      setCopyError(false)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      setCopyError(true)
      setTimeout(() => setCopyError(false), 3000)
    }
  }

  return (
    <div className="rounded-xl border border-indigo-700 bg-indigo-950 p-6">
      <h2 className="mb-3 text-lg font-semibold text-indigo-300">Secret Created!</h2>
      <p className="mb-4 text-sm text-gray-400">
        Share this link with the recipient. The secret can only be decrypted with the passphrase you set.
      </p>
      <div className="flex items-center gap-2">
        <input
          type="text"
          readOnly
          value={url}
          className="min-w-0 flex-1 rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-gray-100 focus:outline-none"
          onClick={(e) => e.target.select()}
        />
        <button
          type="button"
          onClick={handleCopy}
          className="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-950"
        >
          {copied ? 'Copied!' : 'Copy'}
        </button>
      </div>
      {copyError && (
        <p className="mt-2 text-xs text-red-400">
          Could not copy to clipboard. Please copy the link manually.
        </p>
      )}
    </div>
  )
}

export default function Create() {
  const [secret, setSecret]     = useState('')
  const [password, setPassword] = useState('')
  const [ttl, setTtl]           = useState(86_400)
  const [views, setViews]       = useState(0)
  const [errors, setErrors]     = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [apiError, setApiError] = useState('')
  const [secretId, setSecretId] = useState('')

  function validate() {
    const next = {}
    if (!secret.trim()) next.secret = 'Secret text is required.'
    if (!password.trim()) next.password = 'Passphrase is required.'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (!validate()) return

    setSubmitting(true)
    setApiError('')

    try {
      const payload = await encryptSecret(secret.trim(), password)
      const id = await createSecret(payload, ttl, views)
      setSecretId(id.trim())
    } catch (err) {
      setApiError(err.message || 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  function handleReset() {
    setSecret('')
    setPassword('')
    setTtl(86_400)
    setViews(0)
    setErrors({})
    setApiError('')
    setSecretId('')
  }

  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-4xl font-bold text-gray-100">Create a Secret</h1>
      <p className="mb-8 text-lg text-gray-400">
        Your secret is encrypted in your browser before it ever reaches the server.
        Only someone with the passphrase can decrypt it.
      </p>

      {secretId ? (
        <div className="flex flex-col gap-4">
          <ShareableLink secretId={secretId} />
          <button
            type="button"
            onClick={handleReset}
            className="self-start rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-sm font-medium text-gray-300 transition-colors hover:border-gray-500 hover:bg-gray-700"
          >
            Create another secret
          </button>
        </div>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-6">
          {/* Secret textarea */}
          <div>
            <FieldLabel htmlFor="secret">Secret</FieldLabel>
            <textarea
              id="secret"
              rows={5}
              value={secret}
              onInput={(e) => setSecret(e.target.value)}
              placeholder="Enter the secret text you want to share…"
              className={`w-full resize-y rounded-lg border bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:outline-none focus:ring-1 ${
                errors.secret
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-700 focus:border-indigo-500 focus:ring-indigo-500'
              }`}
            />
            {errors.secret && (
              <p className="mt-1 text-xs text-red-400">{errors.secret}</p>
            )}
          </div>

          {/* Passphrase */}
          <div>
            <FieldLabel htmlFor="password">Passphrase</FieldLabel>
            <input
              id="password"
              type="password"
              value={password}
              onInput={(e) => setPassword(e.target.value)}
              placeholder="Choose a strong passphrase"
              className={`w-full rounded-lg border bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:outline-none focus:ring-1 ${
                errors.password
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-700 focus:border-indigo-500 focus:ring-indigo-500'
              }`}
            />
            {errors.password && (
              <p className="mt-1 text-xs text-red-400">{errors.password}</p>
            )}
            <p className="mt-1 text-xs text-gray-500">
              Share this passphrase with the recipient separately — it is never sent to the server.
            </p>
          </div>

          {/* Expiration + View limit */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <FieldLabel htmlFor="ttl">Expires after</FieldLabel>
              <SelectField id="ttl" value={ttl} onChange={setTtl} options={TTL_OPTIONS} />
            </div>
            <div>
              <FieldLabel htmlFor="views">View limit</FieldLabel>
              <SelectField id="views" value={views} onChange={setViews} options={VIEW_OPTIONS} />
            </div>
          </div>

          {apiError && (
            <p className="rounded-lg border border-red-700 bg-red-950 px-4 py-3 text-sm text-red-300">
              {apiError}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="self-start rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-950 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? 'Encrypting…' : 'Create Secret'}
          </button>
        </form>
      )}
    </main>
  )
}
