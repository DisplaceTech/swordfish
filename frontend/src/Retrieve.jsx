export default function Retrieve({ id }) {
  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-4xl font-bold text-content-primary">Retrieve a Secret</h1>
      <p className="mb-8 text-lg text-content-secondary">
        Enter your secret ID and passphrase. Decryption happens entirely in your browser.
      </p>

      <div className="card">
        <form className="flex flex-col gap-6">
          <div className="field">
            <label htmlFor="secret-id" className="label">
              Secret ID
            </label>
            <input
              id="secret-id"
              type="text"
              className="input font-mono"
              placeholder="e.g. a1b2c3d4-e5f6-…"
              defaultValue={id ?? ''}
              autoComplete="off"
              spellCheck="false"
            />
            <p className="hint">
              The unique identifier from the share link you received.
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
              placeholder="Enter the passphrase shared with you"
              autoComplete="current-password"
            />
            <p className="hint">
              Used locally to derive the decryption key. Never transmitted to the server.
            </p>
          </div>

          <div className="flex items-center justify-between gap-4 pt-2">
            <p className="hint max-w-xs">
              Decrypted with AES-GCM-256 in your browser. The server never sees plaintext.
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
                  d="M14.5 1A4.5 4.5 0 0010 5.5V9H3a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5a3 3 0 116 0v2.75a.75.75 0 001.5 0V5.5A4.5 4.5 0 0014.5 1z"
                  clipRule="evenodd"
                />
              </svg>
              Decrypt Secret
            </button>
          </div>
        </form>
      </div>
    </main>
  )
}
