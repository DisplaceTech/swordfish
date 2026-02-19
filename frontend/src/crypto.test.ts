import { describe, it, expect } from 'vitest'
import { encrypt, decrypt, deriveVerifier } from './crypto'

describe('deriveVerifier', () => {
  it('produces a 64-character hex string (256 bits)', async () => {
    const verifier = await deriveVerifier('test-passphrase')
    expect(verifier).toHaveLength(64)
    expect(verifier).toMatch(/^[0-9a-f]+$/)
  })

  it('is deterministic for the same passphrase', async () => {
    const v1 = await deriveVerifier('my-secret-pass')
    const v2 = await deriveVerifier('my-secret-pass')
    expect(v1).toBe(v2)
  })

  it('differs for different passphrases', async () => {
    const v1 = await deriveVerifier('passphrase-one')
    const v2 = await deriveVerifier('passphrase-two')
    expect(v1).not.toBe(v2)
  })
})

describe('encrypt', () => {
  it('returns a string in hex(salt)$hex(verifier)$hex(nonce+ciphertext) format', async () => {
    const result = await encrypt('passphrase', 'hello world')
    const parts = result.split('$')
    expect(parts).toHaveLength(3)

    const [salt, verifier, payload] = parts
    expect(salt).toHaveLength(32)   // 16 bytes → 32 hex chars
    expect(verifier).toHaveLength(64) // 32 bytes → 64 hex chars
    // payload = hex(nonce[12]) + hex(ciphertext[≥1]) → at least 24+2 = 26 hex chars
    expect(payload.length).toBeGreaterThanOrEqual(26)
    expect(payload).toMatch(/^[0-9a-f]+$/)
  })

  it('embeds the correct verifier for the passphrase', async () => {
    const passphrase = 'verify-me'
    const result = await encrypt(passphrase, 'secret text')
    const verifierFromEncrypt = result.split('$')[1]
    const verifierDirect = await deriveVerifier(passphrase)
    expect(verifierFromEncrypt).toBe(verifierDirect)
  })

  it('produces different ciphertexts for the same input (random salt/nonce)', async () => {
    const r1 = await encrypt('pass', 'same plaintext')
    const r2 = await encrypt('pass', 'same plaintext')
    expect(r1).not.toBe(r2)
  })
})

describe('decrypt', () => {
  it('round-trips: decrypt(encrypt output) returns original plaintext', async () => {
    const passphrase = 'round-trip-pass'
    const plaintext = 'my secret message'

    const creationString = await encrypt(passphrase, plaintext)
    const [saltHex, , payload] = creationString.split('$')

    // Server stores bin2hex(salt_bytes . nonce+ciphertext_bytes)
    // which equals hex(salt) + payload
    const encryptedSecret = saltHex + payload

    const recovered = await decrypt(passphrase, encryptedSecret)
    expect(recovered).toBe(plaintext)
  })

  it('throws on wrong passphrase', async () => {
    const creationString = await encrypt('correct-pass', 'secret')
    const [saltHex, , payload] = creationString.split('$')
    const encryptedSecret = saltHex + payload

    await expect(decrypt('wrong-pass', encryptedSecret)).rejects.toThrow()
  })

  it('handles unicode plaintext', async () => {
    const passphrase = 'unicode-pass'
    const plaintext = '🔐 secret émoji café'

    const creationString = await encrypt(passphrase, plaintext)
    const [saltHex, , payload] = creationString.split('$')
    const encryptedSecret = saltHex + payload

    const recovered = await decrypt(passphrase, encryptedSecret)
    expect(recovered).toBe(plaintext)
  })
})
