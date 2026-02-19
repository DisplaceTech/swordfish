import { describe, it, expect } from 'vitest'
import { encrypt, decrypt } from '../crypto'

// Known v1 test vectors — pre-computed with fixed salt, nonce, passphrase, and plaintext.
// These pin the exact PBKDF2 + AES-256-GCM output so any regression in the crypto
// pipeline is caught immediately.
const V1_VECTORS = {
  passphrase: 'correct-horse-battery-staple',
  plaintext: 'Hello, Swordfish!',
  // salt: 16 bytes (00 11 22 … ff)
  saltHex: '00112233445566778899aabbccddeeff',
  // verifier: PBKDF2-SHA-256(passphrase, pepper, 10000 iterations, 256 bits)
  verifierHex: 'ed6872e5db3e4c06b0dfb76eca49a7b4241020a06d5c81d04d09340a3c23c888',
  // nonce: 12 bytes (00 01 02 … 0b)
  nonceHex: '000102030405060708090a0b',
  // ciphertext: AES-256-GCM(key, nonce, plaintext) — includes 16-byte GCM auth tag
  ciphertextHex: '1c583908cb62ca193b7977a6b076f83e58efe562d06358fc4cd77241fb958081d3',
  // blob: hex(salt || nonce || ciphertext) — the format returned by the v1 server
  get blob() {
    return this.saltHex + this.nonceHex + this.ciphertextHex
  },
}

describe('crypto', () => {
  it('encrypt produces a valid v1 wire-format creation string', async () => {
    const creationString = await encrypt('hello, world!', 'test-passphrase')

    // Format: hex(salt)$hex(verifier)$hex(nonce)hex(ciphertext)
    const parts = creationString.split('$')
    expect(parts).toHaveLength(3)

    const [saltHex, verifierHex, payloadHex] = parts

    // salt = 16 bytes → 32 hex chars
    expect(saltHex).toHaveLength(32)
    expect(saltHex).toMatch(/^[0-9a-f]+$/)

    // verifier = 32 bytes (256 bits) → 64 hex chars
    expect(verifierHex).toHaveLength(64)
    expect(verifierHex).toMatch(/^[0-9a-f]+$/)

    // payload = nonce (12 bytes = 24 hex) + ciphertext (≥ 1 byte)
    expect(payloadHex.length).toBeGreaterThan(24)
    expect(payloadHex).toMatch(/^[0-9a-f]+$/)
  })

  it('round-trips plaintext through encrypt then decrypt', async () => {
    const plaintext = 'my secret message'
    const passphrase = 'correct-passphrase'

    const creationString = await encrypt(plaintext, passphrase)

    // Reconstruct the server blob: hex(salt + nonce + ciphertext)
    // Creation string: hex(salt)$hex(verifier)$hex(nonce)hex(ciphertext)
    // Server stores:   hex(salt + nonce + ciphertext) = saltHex + payloadHex
    const parts = creationString.split('$')
    const blob = parts[0] + parts[2]

    const decrypted = await decrypt(blob, passphrase)
    expect(decrypted).toBe(plaintext)
  })

  it('produces a different ciphertext each call (random salt and nonce)', async () => {
    const plaintext = 'same message'
    const passphrase = 'same-passphrase'

    const first = await encrypt(plaintext, passphrase)
    const second = await encrypt(plaintext, passphrase)

    expect(first).not.toBe(second)
  })

  it('rejects decryption with a wrong passphrase', async () => {
    const creationString = await encrypt('secret', 'correct')
    const parts = creationString.split('$')
    const blob = parts[0] + parts[2]

    await expect(decrypt(blob, 'wrong')).rejects.toThrow()
  })

  it('verifier is deterministic for the same passphrase (pepper-based)', async () => {
    const first = await encrypt('msg1', 'my-passphrase')
    const second = await encrypt('msg2', 'my-passphrase')

    const verifier1 = first.split('$')[1]
    const verifier2 = second.split('$')[1]

    // Same passphrase → same verifier (PBKDF2 over fixed pepper)
    expect(verifier1).toBe(verifier2)
  })

  // -------------------------------------------------------------------------
  // Known v1 test vectors
  // -------------------------------------------------------------------------

  it('decrypt known v1 blob returns expected plaintext', async () => {
    const result = await decrypt(V1_VECTORS.blob, V1_VECTORS.passphrase)
    expect(result).toBe(V1_VECTORS.plaintext)
  })

  it('verifier matches known v1 value for fixed passphrase', async () => {
    // encrypt() derives the verifier solely from the passphrase + pepper,
    // so the verifier portion of any creation string must equal the pre-computed value.
    const creationString = await encrypt(V1_VECTORS.plaintext, V1_VECTORS.passphrase)
    const verifierHex = creationString.split('$')[1]
    expect(verifierHex).toBe(V1_VECTORS.verifierHex)
  })

  it('salt generation produces 16-byte (128-bit) random values', async () => {
    const creationString = await encrypt('test', 'passphrase')
    const saltHex = creationString.split('$')[0]
    // 16 bytes → 32 hex characters
    expect(saltHex).toHaveLength(32)
    expect(saltHex).toMatch(/^[0-9a-f]{32}$/)
  })

  it('decrypt is compatible with v1 server blob format (hex(salt||nonce||ciphertext))', async () => {
    // Verify the blob layout: first 32 hex chars = salt, next 24 = nonce, rest = ciphertext
    const blob = V1_VECTORS.blob
    expect(blob.slice(0, 32)).toBe(V1_VECTORS.saltHex)
    expect(blob.slice(32, 56)).toBe(V1_VECTORS.nonceHex)
    expect(blob.slice(56)).toBe(V1_VECTORS.ciphertextHex)

    // And the full round-trip decrypts correctly
    const result = await decrypt(blob, V1_VECTORS.passphrase)
    expect(result).toBe(V1_VECTORS.plaintext)
  })
})
