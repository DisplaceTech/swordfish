const PEPPER = new TextEncoder().encode('d783eff0523c8fa7336bc768c5950f63')

function encodeBuffer(buffer: ArrayBuffer | Uint8Array): string {
  return Array.from(new Uint8Array(buffer))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
}

function fromHex(hex: string): Uint8Array {
  return new Uint8Array(hex.match(/.{1,2}/g)!.map(byte => parseInt(byte, 16)))
}

async function importPassphrase(passphrase: string): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(passphrase),
    { name: 'PBKDF2' },
    false,
    ['deriveBits', 'deriveKey'],
  )
}

/**
 * Derive the PBKDF2 verifier for a passphrase using the hardcoded pepper.
 *
 * Used to authenticate retrieval requests without revealing the passphrase.
 * Returns the verifier as a hex string.
 */
export async function deriveVerifier(passphrase: string): Promise<string> {
  const passkey = await importPassphrase(passphrase)
  const verifier = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: 10000, hash: 'SHA-256' },
    passkey,
    256,
  )
  return encodeBuffer(verifier)
}

/**
 * Encrypt plaintext with the given passphrase using AES-256-GCM.
 *
 * Returns the creation string in v1 format:
 *   hex(salt) + '$' + hex(verifier) + '$' + hex(nonce) + hex(ciphertext)
 */
export async function encrypt(passphrase: string, plaintext: string): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  const passkey = await importPassphrase(passphrase)

  const verifier = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: 10000, hash: 'SHA-256' },
    passkey,
    256,
  )

  const derivedKey = await crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 10000, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )

  const nonce = crypto.getRandomValues(new Uint8Array(12))
  const ciphertext = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    new TextEncoder().encode(plaintext),
  )

  const payload = encodeBuffer(nonce) + encodeBuffer(ciphertext)
  return encodeBuffer(salt) + '$' + encodeBuffer(verifier) + '$' + payload
}

/**
 * Decrypt an encrypted secret payload as returned by the server.
 *
 * The encryptedSecret is the hex-encoded bundle stored by the server:
 *   hex(salt) + hex(nonce) + hex(ciphertext)
 *
 * Returns the decrypted plaintext string.
 */
export async function decrypt(passphrase: string, encryptedSecret: string): Promise<string> {
  const decoded = fromHex(encryptedSecret)
  const salt = decoded.slice(0, 16)
  const nonce = decoded.slice(16, 28)
  const ciphertext = decoded.slice(28)

  const passkey = await importPassphrase(passphrase)
  const derivedKey = await crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 10000, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )

  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    ciphertext,
  )

  return new TextDecoder().decode(plaintext)
}
