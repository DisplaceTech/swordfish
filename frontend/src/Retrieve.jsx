import { useState } from 'preact/hooks'
import { retrieveSecret } from './api.js'
import { deriveVerifier, decrypt } from './crypto.js'

function formatExpiry(expiresAt) {
  if (!expiresAt) return null
  return new Date(expiresAt * 1000).toLocaleString()
}

export default function Retrieve({ id: urlId }) {
  const [secretId, setSecretId] = useState(urlId ?? '')
  const [passphrase, setPassphrase] = useState('')
  const [status, setStatus] = useState('idle')
  const [plaintext, setPlaintext] = useState('')
  const [viewsRemaining, setViewsRemaining] = useState(null)
  const [expiresAt, setExpiresAt] = useState(null)
  const [errorMessage, setErrorMessage] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setStatus('loading')
    setErrorMessage('')
    setPlaintext('')

    try {
      const verifier = await deriveVerifier(passphrase)
      const result = await retrieveSecret(secretId.trim(), verifier)
      const decrypted = await decrypt(result.encrypted_secret, passphrase)
      setPlaintext(decrypted)
      setViewsRemaining(result.views_remaining)
      setExpiresAt(result.expires_at)
      setStatus('success')
    } catch (err) {
      const msg = err.message ?? ''
      if (msg.toLowerCase().includes('invalid authorization') || msg.toLowerCase().includes('unauthorized')) {
        setErrorMessage('Incorrect passphrase. Please try again.')
      } else if (msg.toLowerCase().includes('not found') || msg.toLowerCase().includes('expired')) {
        setErrorMessage('Secret not found or has expired.')
      } else if (msg.toLowerCase().includes('operation-specific error') || msg.toLowerCase().includes('decrypt')) {
        setErrorMessage('Decryption failed. The passphrase may be incorrect.')
      } else {
        setErrorMessage(msg || 'An unexpected error occurred. Please try again.')
      }
      setStatus('error')
    }
  }

  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-4xl font-bold text-gray-100">Retrieve a Secret</h1>
      <p className="mb-8 text-lg text-gray-400">
        Enter your secret ID and passphrase to decrypt and view the secret.
      </p>

      {status !== 'success' && (
        <form onSubmit={handleSubmit} className="flex flex-col gap-5">
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
              className="w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-2.5 text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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
              placeholder="Enter the passphrase"
              required
              className="w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-2.5 text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          {status === 'error' && (
            <div className="rounded-lg border border-red-700 bg-red-950 px-4 py-3 text-sm text-red-300">
              {errorMessage}
            </div>
          )}

          <button
            type="submit"
            disabled={status === 'loading'}
            className="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {status === 'loading' ? 'Decrypting…' : 'Retrieve Secret'}
          </button>
        </form>
      )}

      {status === 'success' && (
        <div className="flex flex-col gap-6">
          <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
            <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">
              Decrypted Secret
            </h2>
            <pre className="whitespace-pre-wrap break-all font-mono text-base text-gray-100">
              {plaintext}
            </pre>
          </div>

          <div className="flex flex-wrap gap-4">
            {viewsRemaining !== null && (
              <div className="flex-1 rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                  Views Remaining
                </p>
                <p className="text-2xl font-bold text-indigo-400">{viewsRemaining}</p>
              </div>
            )}
            {expiresAt !== null && (
              <div className="flex-1 rounded-xl border border-gray-800 bg-gray-900 p-4">
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                  Expires
                </p>
                <p className="text-sm font-medium text-gray-300">{formatExpiry(expiresAt)}</p>
              </div>
            )}
          </div>

          <button
            type="button"
            onClick={() => {
              setStatus('idle')
              setPlaintext('')
              setPassphrase('')
              setViewsRemaining(null)
              setExpiresAt(null)
            }}
            className="self-start rounded-lg border border-gray-700 bg-gray-800 px-5 py-2.5 text-sm font-medium text-gray-100 transition-colors hover:border-gray-500 hover:bg-gray-700"
          >
            Retrieve Another Secret
          </button>
        </div>
      )}
    </main>
  )
}
