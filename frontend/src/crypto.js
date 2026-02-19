/**
 * Crypto module — AES-256-GCM + PBKDF2 encryption matching v1 wire format.
 *
 * Wire formats:
 *   encrypt() output (creation string):
 *     hex(salt[16]) + '$' + hex(verifier[32]) + '$' + hex(nonce[12]) + hex(ciphertext)
 *
 *   decrypt() input (server-stored payload):
 *     hex(salt[16]) + hex(nonce[12]) + hex(ciphertext)
 */

const PEPPER = new TextEncoder().encode('d783eff0523c8fa7336bc768c5950f63')

function encodeBuffer(buffer) {
  return Array.from(new Uint8Array(buffer))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
}

function decodeHex(hex) {
  return new Uint8Array(hex.match(/.{1,2}/g).map(byte => parseInt(byte, 16)))
}

async function importPassphrase(passphrase) {
  return globalThis.crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(passphrase),
    { name: 'PBKDF2' },
    false,
    ['deriveBits', 'deriveKey']
  )
}

/**
 * Derive the verifier for a passphrase using PBKDF2 with the fixed pepper.
 *
 * @param {string} passphrase - Passphrase to derive the verifier from
 * @returns {Promise<string>} Hex-encoded 32-byte verifier
 */
export async function deriveVerifier(passphrase) {
  const passkey = await importPassphrase(passphrase)
  const verifier = await globalThis.crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: 10000, hash: 'SHA-256' },
    passkey,
    256
  )
  return encodeBuffer(verifier)
}

/**
 * Encrypt a secret with a passphrase.
 *
 * @param {string} secret     - Plaintext secret to encrypt
 * @param {string} passphrase - Passphrase used to derive the encryption key
 * @returns {Promise<string>} Creation string: hex(salt)$hex(verifier)$hex(nonce)hex(ciphertext)
 */
export async function encrypt(secret, passphrase) {
  const plaintext = new TextEncoder().encode(secret)
  const salt = globalThis.crypto.getRandomValues(new Uint8Array(16))

  const passkey = await importPassphrase(passphrase)

  const verifier = await globalThis.crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: 10000, hash: 'SHA-256' },
    passkey,
    256
  )

  const derivedKey = await globalThis.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 10000, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt']
  )

  const nonce = globalThis.crypto.getRandomValues(new Uint8Array(12))

  const ciphertext = await globalThis.crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    plaintext
  )

  const payload = encodeBuffer(nonce) + encodeBuffer(ciphertext)
  return encodeBuffer(salt) + '$' + encodeBuffer(verifier) + '$' + payload
}

/**
 * Decrypt a server-stored ciphertext payload with a passphrase.
 *
 * @param {string} ciphertext - Server payload: hex(salt[16])hex(nonce[12])hex(encrypted)
 * @param {string} passphrase - Passphrase used to derive the decryption key
 * @returns {Promise<string>} The decrypted plaintext secret
 */
export async function decrypt(ciphertext, passphrase) {
  const decoded = decodeHex(ciphertext)
  const salt = decoded.slice(0, 16)
  const nonce = decoded.slice(16, 28)
  const encrypted = decoded.slice(28)

  const passkey = await importPassphrase(passphrase)

  const derivedKey = await globalThis.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 10000, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt']
  )

  const plaintext = await globalThis.crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    encrypted
  )

  return new TextDecoder().decode(plaintext)
}
