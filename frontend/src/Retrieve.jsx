import { useState, useEffect } from 'preact/hooks'
import { getVerifier, decrypt } from './crypto'
import { retrieveSecret } from './api'

const ERROR_MESSAGES = {
  'not found': 'Secret not found. It may have expired or been fully viewed.',
  'expired': 'This secret has expired.',
  'views exceeded': 'This secret has no views remaining.',
}

function friendlyError(message) {
  const lower = message.toLowerCase()
  for (const [key, friendly] of Object.entries(ERROR_MESSAGES)) {
    if (lower.includes(key)) return friendly
  }
  if (lower.includes('decrypt') || lower.includes('operation')) {
    return 'Incorrect passphrase. Please try again.'
  }
  return message
}

function formatExpiry(timestamp) {
  return new Date(timestamp * 1000).toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}

export default function Retrieve({ id: urlId }) {
  const [secretId, setSecretId] = useState(urlId ?? '')
  const [passphrase, setPassphrase] = useState('')
  const [status, setStatus] = useState('idle') // idle | loading | success | error
  const [plaintext, setPlaintext] = useState('')
  const [viewsRemaining, setViewsRemaining] = useState(null)
  const [expiresAt, setExpiresAt] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    if (urlId) setSecretId(urlId)
  }, [urlId])

  async function handleSubmit(e) {
    e.preventDefault()
    setStatus('loading')
    setError('')

    try {
      const verifier = await getVerifier(passphrase)
      const data = await retrieveSecret(secretId.trim(), verifier)
      const text = await decrypt(data.encrypted_secret, passphrase)

      setPlaintext(text)
      setViewsRemaining(data.views_remaining)
      setExpiresAt(data.expires_at)
      setStatus('success')
    } catch (err) {
      setError(friendlyError(err.message ?? 'Failed to retrieve secret'))
      setStatus('error')
    }
  }

  if (status === 'success') {
    return (
      <main className="mx-auto max-w-3xl px-4 py-12">
        <h1 className="mb-6 text-3xl font-bold text-gray-100 sm:text-4xl">Your Secret</h1>

        <div className="mb-6 rounded-lg border border-gray-700 bg-gray-900 p-6">
          <p className="whitespace-pre-wrap break-words font-mono text-gray-100">{plaintext}</p>
        </div>

        <div className="flex flex-wrap gap-4 text-sm text-gray-400">
          <span className="flex items-center gap-1.5">
            <span aria-hidden="true" className="inline-block h-2 w-2 rounded-full bg-indigo-400" />
            {viewsRemaining === 0
              ? 'No views remaining — this secret has been deleted'
              : `${viewsRemaining} view${viewsRemaining === 1 ? '' : 's'} remaining`}
          </span>
          {expiresAt !== null && (
            <span className="flex items-center gap-1.5">
              <span aria-hidden="true" className="inline-block h-2 w-2 rounded-full bg-indigo-400" />
              Expires {formatExpiry(expiresAt)}
            </span>
          )}
        </div>

        <button
          type="button"
          onClick={() => {
            setStatus('idle')
            setPlaintext('')
            setPassphrase('')
            setSecretId(urlId ?? '')
          }}
          className="mt-8 inline-block py-3 text-sm text-indigo-400 hover:text-indigo-300 transition-colors"
        >
          <span aria-hidden="true">← </span>Retrieve another secret
        </button>
      </main>
    )
  }

  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-3xl font-bold text-gray-100 sm:text-4xl">Retrieve a Secret</h1>
      <p className="mb-8 text-lg text-gray-400">
        Enter your secret ID and passphrase to decrypt and view the secret.
      </p>

      <form onSubmit={handleSubmit} className="space-y-5">
        <div>
          <label htmlFor="secret-id" className="mb-1.5 block text-sm font-medium text-gray-300">
            Secret ID
          </label>
          <input
            id="secret-id"
            type="text"
            value={secretId}
            onInput={e => setSecretId(e.target.value)}
            placeholder="e.g. a1b2c3d4e5f6"
            required
            className="w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-2.5 font-mono text-sm text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label htmlFor="passphrase" className="mb-1.5 block text-sm font-medium text-gray-300">
            Passphrase
          </label>
          <input
            id="passphrase"
            type="password"
            value={passphrase}
            onInput={e => setPassphrase(e.target.value)}
            placeholder="Enter the passphrase used to encrypt this secret"
            required
            className="w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        {status === 'error' && (
          <p role="alert" className="rounded-lg border border-red-800 bg-red-950 px-4 py-3 text-sm text-red-300">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={status === 'loading'}
          className="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {status === 'loading' ? 'Decrypting…' : 'Retrieve Secret'}
        </button>
      </form>
    </main>
  )
}
