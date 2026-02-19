const GITHUB_URL = 'https://github.com/DisplaceTech/swordfish'

const steps = [
  {
    number: '01',
    title: 'You type your secret',
    description:
      'Your secret text and passphrase are entered locally in your browser. Nothing is sent to any server at this stage.',
  },
  {
    number: '02',
    title: 'Your browser generates a random salt',
    description:
      'A cryptographically random 16-byte salt is generated using the Web Crypto API. This salt is unique to every secret you create.',
  },
  {
    number: '03',
    title: 'Your passphrase is stretched with PBKDF2',
    description:
      'Using PBKDF2-SHA-256 with 10,000 iterations, your passphrase is used to derive two values entirely in-browser: an AES-GCM-256 encryption key (keyed to the random salt) and a verifier (keyed to a fixed pepper). The verifier lets the server authenticate retrieval requests without ever seeing your passphrase.',
  },
  {
    number: '04',
    title: 'Your secret is encrypted with AES-GCM-256',
    description:
      'A random 12-byte nonce is generated and your secret is encrypted locally using AES-GCM-256 with the derived key. The result is an authenticated ciphertext that cannot be decrypted without your passphrase.',
  },
  {
    number: '05',
    title: 'Only ciphertext reaches the server',
    description:
      'The server receives the encrypted payload (salt + nonce + ciphertext) and a bcrypt-hashed verifier. Your plaintext secret and your passphrase never leave your browser.',
  },
  {
    number: '06',
    title: 'Retrieval is passphrase-gated and decrypted locally',
    description:
      'To retrieve a secret, your browser re-derives the verifier from your passphrase and sends it to authenticate. The server checks it against the stored bcrypt hash and, if it matches, returns the ciphertext. Your browser then decrypts it locally — the server never sees the plaintext.',
  },
]

function StepCard({ number, title, description }) {
  return (
    <div className="flex gap-4 rounded-xl border border-gray-800 bg-gray-900 p-6">
      <span className="shrink-0 text-2xl font-bold text-indigo-400">{number}</span>
      <div>
        <h3 className="mb-1 text-lg font-semibold text-gray-100">{title}</h3>
        <p className="text-sm leading-relaxed text-gray-400">{description}</p>
      </div>
    </div>
  )
}

export default function About() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-12">
      <h1 className="mb-2 text-3xl font-bold text-gray-100 sm:text-4xl">How It Works</h1>
      <p className="mb-10 text-lg text-gray-400">
        Swordfish encrypts your secrets entirely in your browser before they ever touch a server.
        Here is the step-by-step flow.
      </p>

      <div className="mb-12 flex flex-col gap-4">
        {steps.map((step) => (
          <StepCard key={step.number} {...step} />
        ))}
      </div>

      <div className="mb-12 rounded-xl border border-indigo-700 bg-indigo-950 p-6">
        <h2 className="mb-4 text-xl font-bold text-indigo-300">Security Guarantees</h2>
        <ul className="space-y-3 text-sm text-gray-300">
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-indigo-400">✓</span>
            <span>
              <strong className="text-gray-100">Your secret never leaves your browser in plaintext.</strong>{' '}
              Encryption happens locally using the Web Crypto API before any network request is made.
            </span>
          </li>
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-indigo-400">✓</span>
            <span>
              <strong className="text-gray-100">Your passphrase never leaves your browser.</strong>{' '}
              Only a PBKDF2-derived verifier (bcrypt-hashed server-side) is transmitted — the raw
              passphrase is never sent over the wire.
            </span>
          </li>
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-indigo-400">✓</span>
            <span>
              <strong className="text-gray-100">The server cannot read your secrets.</strong>{' '}
              The server stores only AES-GCM ciphertext. Without the passphrase, the stored data is
              meaningless.
            </span>
          </li>
          <li className="flex items-start gap-2">
            <span className="mt-0.5 text-indigo-400">✓</span>
            <span>
              <strong className="text-gray-100">Decryption happens locally on retrieval.</strong>{' '}
              The encrypted payload is returned to your browser and decrypted there — the plaintext
              is never transmitted back from the server either.
            </span>
          </li>
        </ul>
      </div>

      <div className="text-center">
        <p className="mb-4 text-sm text-gray-500">
          Swordfish is open source. Read the code, audit the crypto, or contribute.
        </p>
        <a
          href={GITHUB_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 rounded-lg border border-gray-700 bg-gray-800 px-5 py-3 text-sm font-medium text-gray-100 transition-colors hover:border-gray-500 hover:bg-gray-700"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="currentColor"
            className="h-5 w-5"
            aria-hidden="true"
          >
            <path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.335-1.755-1.335-1.755-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 21.795 24 17.295 24 12c0-6.63-5.37-12-12-12z" />
          </svg>
          View on GitHub
        </a>
      </div>
    </main>
  )
}
