import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { buildRetrievalPayload, decryptSecret } from '../crypto.js'

export default function RetrieveSecret() {
  const { secretId: paramId } = useParams()
  const [secretId, setSecretId] = useState(paramId ?? '')
  const [passphrase, setPassphrase] = useState('')
  const [status, setStatus] = useState('idle') // idle | loading | success | error
  const [plaintext, setPlaintext] = useState('')
  const [errorMsg, setErrorMsg] = useState('')

  useEffect(() => {
    if (paramId) setSecretId(paramId)
  }, [paramId])

  async function handleSubmit(e) {
    e.preventDefault()

    if (!secretId.trim() || !passphrase.trim()) {
      setErrorMsg('You must enter a secret ID and a passphrase.')
      setStatus('error')
      return
    }

    setStatus('loading')
    setErrorMsg('')
    setPlaintext('')

    try {
      const payload = await buildRetrievalPayload(secretId.trim(), passphrase)
      const response = await fetch('/retrieve', {
        method: 'POST',
        cache: 'no-cache',
        headers: { 'Content-Type': 'text/plain' },
        body: payload,
      })

      if (response.status === 200) {
        const ciphertext = await response.text()
        const decoded = await decryptSecret(ciphertext, passphrase)
        setPlaintext(decoded)
        setStatus('success')
      } else if (response.status === 401) {
        setErrorMsg('Your passphrase is incorrect.')
        setStatus('error')
      } else if (response.status === 404) {
        setErrorMsg('That secret does not exist or has expired.')
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
    setPlaintext('')
    setErrorMsg('')
    setPassphrase('')
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-sans text-2xl font-semibold text-zinc-100">Retrieve a secret</h1>
        <p className="mt-1 font-sans text-sm text-zinc-400">
          Enter the secret ID and passphrase. Decryption happens entirely in your browser.
        </p>
      </div>

      {status === 'error' && (
        <div className="rounded-md border border-red-800 bg-red-950/40 px-4 py-3">
          <p className="font-sans text-sm text-red-400">{errorMsg}</p>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label htmlFor="secretid" className="block font-sans text-xs font-medium uppercase tracking-widest text-zinc-500 mb-1.5">
            Secret ID
          </label>
          <input
            id="secretid"
            type="text"
            className="field"
            placeholder="123456789abc"
            value={secretId}
            onChange={e => setSecretId(e.target.value)}
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
          {status === 'loading' ? 'Decrypting…' : 'Retrieve and decrypt secret'}
        </button>
      </form>

      {status === 'success' && plaintext && (
        <div className="space-y-2">
          <p className="font-sans text-xs font-medium uppercase tracking-widest text-zinc-500">
            Decrypted secret
          </p>
          <textarea
            readOnly
            rows={8}
            className="field resize-none"
            value={plaintext}
          />
          <button onClick={handleReset} className="btn-ghost w-full">
            Retrieve another secret
          </button>
        </div>
      )}
    </div>
  )
}
