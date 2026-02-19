import { describe, it, expect } from 'vitest'
import { encrypt, decrypt, getVerifier } from '../crypto'

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
})

describe('getVerifier', () => {
  it('returns a 64-char lowercase hex string', async () => {
    const verifier = await getVerifier('test-passphrase')
    expect(verifier).toHaveLength(64)
    expect(verifier).toMatch(/^[0-9a-f]+$/)
  })

  it('is deterministic for the same passphrase', async () => {
    const v1 = await getVerifier('my-passphrase')
    const v2 = await getVerifier('my-passphrase')
    expect(v1).toBe(v2)
  })

  it('differs for different passphrases', async () => {
    const v1 = await getVerifier('passphrase-one')
    const v2 = await getVerifier('passphrase-two')
    expect(v1).not.toBe(v2)
  })

  it('matches the verifier embedded in the encrypt output', async () => {
    const passphrase = 'shared-passphrase'
    const creationString = await encrypt('any plaintext', passphrase)
    const embeddedVerifier = creationString.split('$')[1]
    const standaloneVerifier = await getVerifier(passphrase)
    expect(standaloneVerifier).toBe(embeddedVerifier)
  })
})
