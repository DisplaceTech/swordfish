/**
 * Client-side cryptography for Swordfish.
 *
 * Encryption flow:
 *   1. Generate a random 16-byte salt.
 *   2. Derive an AES-GCM-256 key from the passphrase + salt via PBKDF2-SHA-256 (10 000 iterations).
 *   3. Derive a 32-byte verifier from the passphrase + fixed pepper via PBKDF2-SHA-256 (10 000 iterations).
 *   4. Encrypt the secret with AES-GCM-256 using a random 12-byte nonce.
 *   5. Return the serialised payload: hex(salt)$hex(verifier)$hex(nonce‖ciphertext).
 */

const PBKDF2_ITERATIONS = 10_000
const VERIFIER_PEPPER = new TextEncoder().encode('swordfish-verifier-pepper-v1')

/**
 * Encode a Uint8Array as a lowercase hex string.
 *
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function toHex(bytes) {
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}

/**
 * Import a passphrase string as raw PBKDF2 key material.
 *
 * @param {string} passphrase
 * @returns {Promise<CryptoKey>}
 */
async function importPassphrase(passphrase) {
  return crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(passphrase),
    'PBKDF2',
    false,
    ['deriveBits', 'deriveKey'],
  )
}

/**
 * Derive an AES-GCM-256 encryption key from the passphrase and salt.
 *
 * @param {CryptoKey} keyMaterial
 * @param {Uint8Array} salt
 * @returns {Promise<CryptoKey>}
 */
async function deriveEncryptionKey(keyMaterial, salt) {
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    keyMaterial,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt'],
  )
}

/**
 * Derive a 32-byte verifier from the passphrase using a fixed pepper.
 *
 * @param {CryptoKey} keyMaterial
 * @returns {Promise<Uint8Array>}
 */
async function deriveVerifier(keyMaterial) {
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: VERIFIER_PEPPER, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    keyMaterial,
    256,
  )
  return new Uint8Array(bits)
}

/**
 * Encrypt a secret string with the given passphrase.
 *
 * Returns a serialised payload string in the format:
 *   hex(salt)$hex(verifier)$hex(nonce‖ciphertext)
 *
 * @param {string} secret     - Plaintext secret to encrypt
 * @param {string} passphrase - User-supplied passphrase
 * @returns {Promise<string>} Serialised payload
 */
export async function encryptSecret(secret, passphrase) {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  const nonce = crypto.getRandomValues(new Uint8Array(12))

  const keyMaterial = await importPassphrase(passphrase)
  const [encKey, verifier] = await Promise.all([
    deriveEncryptionKey(keyMaterial, salt),
    deriveVerifier(keyMaterial),
  ])

  const ciphertext = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    encKey,
    new TextEncoder().encode(secret),
  )

  const encryptedPayload = new Uint8Array(nonce.byteLength + ciphertext.byteLength)
  encryptedPayload.set(nonce, 0)
  encryptedPayload.set(new Uint8Array(ciphertext), nonce.byteLength)

  return `${toHex(salt)}$${toHex(verifier)}$${toHex(encryptedPayload)}`
}
