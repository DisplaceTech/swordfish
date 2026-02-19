import { useState } from 'react'
import { encryptSecret } from '../crypto.js'

export default function CreateSecret() {
  const [secret, setSecret] = useState('')
  const [passphrase, setPassphrase] = useState('')
  const [status, setStatus] = useState('idle') // idle | loading | success | error
  const [result, setResult] = useState(null)
  const [errorMsg, setErrorMsg] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()

    if (!secret.trim() || !passphrase.trim()) {
      setErrorMsg('You forgot your secret or your passphrase!')
      setStatus('error')
      return
    }

    setStatus('loading')
    setErrorMsg('')

    try {
      const payload = await encryptSecret(secret, passphrase)
      const response = await fetch('/create', {
        method: 'POST',
        cache: 'no-cache',
        headers: { 'Content-Type': 'text/plain' },
        body: payload,
      })

      if (response.status === 201) {
        const code = await response.text()
        setResult({ code, passphrase })
        setSecret('')
        setPassphrase('')
        setStatus('success')
      } else if (response.status === 413) {
        setErrorMsg('Your secret was too large for the server to keep it.')
        setStatus('error')
      } else {
        setErrorMsg('Something went wrong. Please try again.')
        setStatus('error')
      }
    } catch {
      setErrorMsg('A network error occurred. Please try again.')
      setStatus('error')
    }
  }

  function handleReset() {
    setStatus('idle')
    setResult(null)
    setErrorMsg('')
  }

  if (status === 'success' && result) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="font-sans text-2xl font-semibold text-zinc-100">Secret created</h1>
          <p className="mt-1 font-sans text-sm text-zinc-400">
            Your secret is encrypted and stored. Share the ID and passphrase securely.
          </p>
        </div>

        <div className="card space-y-4">
          <div>
            <p className="font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
              Secret ID
            </p>
            <p className="font-mono text-lg text-cipher-400 select-all">{result.code}</p>
          </div>
          <div>
            <p className="font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
              Passphrase
            </p>
            <p className="font-mono text-lg text-zinc-200 select-all">{result.passphrase}</p>
          </div>
          <div>
            <p className="font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
              Retrieval link
            </p>
            <a
              href={`/secret/${result.code}`}
              className="font-mono text-sm text-cipher-500 hover:text-cipher-400 transition-colors break-all"
            >
              {window.location.origin}/secret/{result.code}
            </a>
          </div>
        </div>

        <button onClick={handleReset} className="btn-ghost w-full">
          Create another secret
        </button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-sans text-2xl font-semibold text-zinc-100">
          Truly secure, anonymous secret sharing
        </h1>
        <p className="mt-1 font-sans text-sm text-zinc-400">
          Paste your secret below and protect it with a passphrase. Encrypted in your browser before
          it ever leaves your device.
        </p>
      </div>

      {status === 'error' && (
        <div className="rounded-md border border-red-800 bg-red-950/40 px-4 py-3">
          <p className="font-sans text-sm text-red-400">{errorMsg}</p>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label htmlFor="secret" className="block font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
            Secret
          </label>
          <textarea
            id="secret"
            rows={8}
            className="field resize-none"
            placeholder="Keep it secret, keep it safe…"
            value={secret}
            onChange={e => setSecret(e.target.value)}
            disabled={status === 'loading'}
          />
        </div>

        <div>
          <label htmlFor="passphrase" className="block font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
            Passphrase
          </label>
          <input
            id="passphrase"
            type="text"
            className="field"
            placeholder="correct battery horse staple"
            value={passphrase}
            onChange={e => setPassphrase(e.target.value)}
            disabled={status === 'loading'}
          />
        </div>

        <button
          type="submit"
          className="btn-primary w-full"
          disabled={status === 'loading'}
        >
          {status === 'loading' ? 'Encrypting…' : 'Encrypt and store secret'}
        </button>
      </form>
    </div>
  )
}
