const PEPPER = new TextEncoder().encode('d783eff0523c8fa7336bc768c5950f63')
const PBKDF2_ITERATIONS = 10000

function toHex(buffer: ArrayBuffer | Uint8Array): string {
  return Array.from(new Uint8Array(buffer))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
}

function fromHex(hex: string): Uint8Array {
  const matches = hex.match(/.{1,2}/g)
  if (!matches) throw new Error('Invalid hex string')
  return new Uint8Array(matches.map(b => parseInt(b, 16)))
}

async function importPassphrase(passphrase: string): Promise<CryptoKey> {
  return globalThis.crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(passphrase),
    { name: 'PBKDF2' },
    false,
    ['deriveBits', 'deriveKey'],
  )
}

async function deriveVerifier(passkey: CryptoKey): Promise<ArrayBuffer> {
  return globalThis.crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passkey,
    256,
  )
}

async function deriveKey(passkey: CryptoKey, salt: Uint8Array): Promise<CryptoKey> {
  return globalThis.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )
}

/**
 * Encrypt a plaintext secret with a passphrase.
 *
 * Returns a wire-format creation string compatible with the v1 server:
 *   hex(salt) + '$' + hex(verifier) + '$' + hex(nonce) + hex(ciphertext)
 *
 * The verifier is a PBKDF2 digest over the pepper used by the server to
 * authenticate retrieval requests without storing the passphrase.
 */
export async function encrypt(plaintext: string, passphrase: string): Promise<string> {
  const salt = globalThis.crypto.getRandomValues(new Uint8Array(16))
  const nonce = globalThis.crypto.getRandomValues(new Uint8Array(12))

  const passkey = await importPassphrase(passphrase)
  const verifier = await deriveVerifier(passkey)
  const key = await deriveKey(passkey, salt)

  const ciphertext = await globalThis.crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    key,
    new TextEncoder().encode(plaintext),
  )

  const payload = toHex(nonce) + toHex(ciphertext)
  return `${toHex(salt)}$${toHex(verifier)}$${payload}`
}

/**
 * Decrypt a ciphertext blob returned by the server with a passphrase.
 *
 * The blob is the hex-encoded concatenation of salt (16 bytes), nonce (12 bytes),
 * and AES-GCM ciphertext, matching the format stored and returned by the v1 server.
 */
export async function decrypt(blob: string, passphrase: string): Promise<string> {
  const decoded = fromHex(blob)
  const salt = decoded.slice(0, 16)
  const nonce = decoded.slice(16, 28)
  const encrypted = decoded.slice(28)

  const passkey = await importPassphrase(passphrase)
  const key = await deriveKey(passkey, salt)

  const plaintext = await globalThis.crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: nonce },
    key,
    encrypted,
  )

  return new TextDecoder().decode(plaintext)
}
