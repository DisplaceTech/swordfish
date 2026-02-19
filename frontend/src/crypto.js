const PEPPER = 'd783eff0523c8fa7336bc768c5950f63'
const PBKDF2_ITERATIONS = 10000
const SALT_BYTES = 16
const NONCE_BYTES = 12

function toHex(buffer) {
  return Array.from(new Uint8Array(buffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}

function fromHex(hex) {
  const bytes = new Uint8Array(hex.length / 2)
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.slice(i, i + 2), 16)
  }
  return bytes
}

function encodeText(str) {
  return new TextEncoder().encode(str)
}

async function importPasswordKey(password) {
  return crypto.subtle.importKey('raw', encodeText(password), 'PBKDF2', false, [
    'deriveBits',
    'deriveKey',
  ])
}

async function deriveEncryptionKey(passwordKey, salt) {
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passwordKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
}

/**
 * Derive the 32-byte verifier used to authenticate secret retrieval requests.
 * Uses the pepper as the PBKDF2 salt, matching the v1 CLI implementation.
 *
 * @param {string} password
 * @returns {Promise<string>} hex-encoded 32-byte verifier
 */
export async function deriveVerifier(password) {
  const passwordKey = await importPasswordKey(password)
  const bits = await crypto.subtle.deriveBits(
    {
      name: 'PBKDF2',
      salt: encodeText(PEPPER),
      iterations: PBKDF2_ITERATIONS,
      hash: 'SHA-256',
    },
    passwordKey,
    256,
  )
  return toHex(bits)
}

/**
 * Encrypt a plaintext secret with AES-256-GCM using a PBKDF2-derived key.
 *
 * Wire format: hex(salt)$hex(verifier)$hex(nonce+ciphertext)
 *
 * @param {string} secret - plaintext to encrypt
 * @param {string} password - user password
 * @returns {Promise<string>} wire-format string
 */
export async function encrypt(secret, password) {
  const salt = crypto.getRandomValues(new Uint8Array(SALT_BYTES))
  const nonce = crypto.getRandomValues(new Uint8Array(NONCE_BYTES))

  const passwordKey = await importPasswordKey(password)
  const encryptionKey = await deriveEncryptionKey(passwordKey, salt)

  const ciphertext = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    encryptionKey,
    encodeText(secret),
  )

  const verifier = await deriveVerifier(password)
  const encryptedBlob = new Uint8Array(nonce.length + ciphertext.byteLength)
  encryptedBlob.set(nonce, 0)
  encryptedBlob.set(new Uint8Array(ciphertext), nonce.length)

  return `${toHex(salt)}$${verifier}$${toHex(encryptedBlob)}`
}

/**
 * Decrypt a secret from the server's stored hex blob.
 *
 * The server returns hex(salt + nonce + ciphertext). This function derives
 * the encryption key from the password and salt, then decrypts.
 *
 * @param {string} hexBlob - hex-encoded salt+nonce+ciphertext from the server
 * @param {string} password - user password
 * @returns {Promise<string>} decrypted plaintext
 */
export async function decrypt(hexBlob, password) {
  const raw = fromHex(hexBlob)
  const salt = raw.slice(0, SALT_BYTES)
  const nonce = raw.slice(SALT_BYTES, SALT_BYTES + NONCE_BYTES)
  const ciphertext = raw.slice(SALT_BYTES + NONCE_BYTES)

  const passwordKey = await importPasswordKey(password)
  const encryptionKey = await deriveEncryptionKey(passwordKey, salt)

  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: nonce },
    encryptionKey,
    ciphertext,
  )

  return new TextDecoder().decode(plaintext)
}
