const PEPPER = new TextEncoder().encode('d783eff0523c8fa7336bc768c5950f63')
const PBKDF2_ITERATIONS = 10000

function encodeBuffer(buffer) {
  return Array.from(new Uint8Array(buffer))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
}

function fromHexString(hexString) {
  return new Uint8Array(hexString.match(/.{1,2}/g).map(byte => parseInt(byte, 16)))
}

async function importPasskey(passphrase) {
  return window.crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(passphrase),
    { name: 'PBKDF2' },
    false,
    ['deriveBits', 'deriveKey'],
  )
}

async function deriveVerifier(passkey) {
  const bits = await window.crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: PEPPER, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passkey,
    256,
  )
  return encodeBuffer(bits)
}

export async function encryptSecret(plaintext, passphrase) {
  const encoder = new TextEncoder()
  const salt = window.crypto.getRandomValues(new Uint8Array(16))
  const passkey = await importPasskey(passphrase)
  const verifier = await deriveVerifier(passkey)

  const derivedKey = await window.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )

  const nonce = window.crypto.getRandomValues(new Uint8Array(12))
  const ciphertext = await window.crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    encoder.encode(plaintext),
  )

  const payload = encodeBuffer(nonce) + encodeBuffer(ciphertext)
  const creationString = encodeBuffer(salt) + '$' + verifier + '$' + payload

  return creationString
}

export async function buildRetrievalPayload(secretId, passphrase) {
  const passkey = await importPasskey(passphrase)
  const verifier = await deriveVerifier(passkey)
  return secretId + '$' + verifier
}

export async function decryptSecret(ciphertext, passphrase) {
  const decoded = fromHexString(ciphertext)
  const salt = decoded.slice(0, 16)
  const nonce = decoded.slice(16, 28)
  const encrypted = decoded.slice(28)

  const passkey = await importPasskey(passphrase)
  const derivedKey = await window.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    passkey,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt'],
  )

  const decrypted = await window.crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: nonce },
    derivedKey,
    encrypted,
  )

  return new TextDecoder().decode(decrypted)
}
