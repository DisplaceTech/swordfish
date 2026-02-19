export default function Create() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-4xl font-bold text-content-primary">Create a Secret</h1>
      <p className="mb-8 text-lg text-content-secondary">
        Your secret is encrypted entirely in your browser before it reaches our servers.
      </p>

      <div className="card mb-6">
        <form className="flex flex-col gap-6">
          <div className="field">
            <label htmlFor="secret" className="label">
              Secret
            </label>
            <textarea
              id="secret"
              className="textarea h-36"
              placeholder="Enter the text you want to encrypt and share…"
              autoComplete="off"
              spellCheck="false"
            />
            <p className="hint">
              Your secret is encrypted locally and never sent in plaintext.
            </p>
          </div>

          <div className="field">
            <label htmlFor="passphrase" className="label">
              Passphrase
            </label>
            <input
              id="passphrase"
              type="password"
              className="input font-mono"
              placeholder="Choose a strong passphrase"
              autoComplete="new-password"
            />
            <p className="hint">
              The recipient will need this passphrase to decrypt the secret. It never leaves your
              browser.
            </p>
          </div>

          <div className="flex items-center justify-between gap-4 pt-2">
            <p className="hint max-w-xs">
              Encrypted with AES-GCM-256. Key derived via PBKDF2-SHA-256.
            </p>
            <button type="submit" className="btn-primary shrink-0">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 20 20"
                fill="currentColor"
                className="h-4 w-4"
                aria-hidden="true"
              >
                <path
                  fillRule="evenodd"
                  d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z"
                  clipRule="evenodd"
                />
              </svg>
              Encrypt &amp; Share
            </button>
          </div>
        </form>
      </div>

      <div className="card-accent">
        <h2 className="mb-3 text-sm font-semibold text-accent-text">How your secret is protected</h2>
        <ul className="space-y-2 text-sm text-content-secondary">
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-accent-muted" aria-hidden="true">✓</span>
            Encrypted in your browser with AES-GCM-256 before any network request.
          </li>
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-accent-muted" aria-hidden="true">✓</span>
            Your passphrase never leaves your device — only a derived verifier is transmitted.
          </li>
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-accent-muted" aria-hidden="true">✓</span>
            The server stores only ciphertext and cannot read your secret.
          </li>
        </ul>
      </div>
    </main>
  )
}
