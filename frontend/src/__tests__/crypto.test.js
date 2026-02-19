import { describe, it, expect } from 'vitest'
import { encryptSecret } from '../crypto.js'

describe('encryptSecret', () => {
  it('returns a string with three $-delimited hex segments', async () => {
    const result = await encryptSecret('my secret', 'passphrase')
    const parts = result.split('$')
    expect(parts).toHaveLength(3)
  })

  it('produces a 32-char hex salt (16 bytes)', async () => {
    const result = await encryptSecret('hello', 'pass')
    const [salt] = result.split('$')
    expect(salt).toMatch(/^[0-9a-f]{32}$/)
  })

  it('produces a 64-char hex verifier (32 bytes)', async () => {
    const result = await encryptSecret('hello', 'pass')
    const [, verifier] = result.split('$')
    expect(verifier).toMatch(/^[0-9a-f]{64}$/)
  })

  it('produces a hex-encoded encrypted payload (nonce + ciphertext)', async () => {
    const result = await encryptSecret('hello', 'pass')
    const [, , payload] = result.split('$')
    // nonce = 12 bytes = 24 hex chars; ciphertext >= 1 byte + 16-byte GCM tag
    expect(payload.length).toBeGreaterThan(24 + 32)
    expect(payload).toMatch(/^[0-9a-f]+$/)
  })

  it('produces different ciphertexts for the same input (random salt/nonce)', async () => {
    const a = await encryptSecret('same secret', 'same pass')
    const b = await encryptSecret('same secret', 'same pass')
    expect(a).not.toBe(b)
  })

  it('produces the same verifier for the same passphrase (deterministic pepper)', async () => {
    const a = await encryptSecret('secret1', 'mypassword')
    const b = await encryptSecret('secret2', 'mypassword')
    const verifierA = a.split('$')[1]
    const verifierB = b.split('$')[1]
    expect(verifierA).toBe(verifierB)
  })

  it('produces different verifiers for different passphrases', async () => {
    const a = await encryptSecret('secret', 'passA')
    const b = await encryptSecret('secret', 'passB')
    expect(a.split('$')[1]).not.toBe(b.split('$')[1])
  })
})
