import { describe, it, expect } from 'vitest'
import { encrypt, decrypt, deriveVerifier } from './crypto.js'

const SALT_HEX_LEN = 32   // 16 bytes
const VERIFIER_HEX_LEN = 64  // 32 bytes
const NONCE_BYTES = 12
const GCM_TAG_BYTES = 16

describe('deriveVerifier', () => {
  it('returns a 64-character hex string', async () => {
    const verifier = await deriveVerifier('testpassword')
    expect(verifier).toMatch(/^[0-9a-f]{64}$/)
  })

  it('is deterministic for the same password', async () => {
    const v1 = await deriveVerifier('mypassword')
    const v2 = await deriveVerifier('mypassword')
    expect(v1).toBe(v2)
  })

  it('differs for different passwords', async () => {
    const v1 = await deriveVerifier('password1')
    const v2 = await deriveVerifier('password2')
    expect(v1).not.toBe(v2)
  })

  it('matches the v1 PHP/CLI PBKDF2-SHA256 output', async () => {
    // Reference value computed with Node crypto.pbkdf2Sync:
    //   pbkdf2Sync('testpassword', 'd783eff0523c8fa7336bc768c5950f63', 10000, 32, 'sha256')
    const expected = '07e76dbe7a7918d245cb958f0300b57eb3c7274cdc2ffb49edc4eb76ae5e459f'
    const verifier = await deriveVerifier('testpassword')
    expect(verifier).toBe(expected)
  })
})

describe('encrypt', () => {
  it('returns a string with three $-delimited segments', async () => {
    const wire = await encrypt('hello world', 'password')
    const parts = wire.split('$')
    expect(parts).toHaveLength(3)
  })

  it('salt segment is 32 hex characters (16 bytes)', async () => {
    const wire = await encrypt('hello world', 'password')
    const [salt] = wire.split('$')
    expect(salt).toMatch(/^[0-9a-f]{32}$/)
  })

  it('verifier segment is 64 hex characters (32 bytes)', async () => {
    const wire = await encrypt('hello world', 'password')
    const [, verifier] = wire.split('$')
    expect(verifier).toMatch(/^[0-9a-f]{64}$/)
  })

  it('encrypted segment contains nonce + ciphertext + GCM tag', async () => {
    const plaintext = 'hello world'
    const wire = await encrypt(plaintext, 'password')
    const [, , encryptedHex] = wire.split('$')
    const encryptedBytes = encryptedHex.length / 2
    const expectedBytes = NONCE_BYTES + plaintext.length + GCM_TAG_BYTES
    expect(encryptedBytes).toBe(expectedBytes)
  })

  it('verifier matches deriveVerifier for the same password', async () => {
    const password = 'mypassword'
    const wire = await encrypt('secret', password)
    const [, verifier] = wire.split('$')
    const expected = await deriveVerifier(password)
    expect(verifier).toBe(expected)
  })

  it('produces different ciphertexts for the same input (random salt/nonce)', async () => {
    const wire1 = await encrypt('same secret', 'same password')
    const wire2 = await encrypt('same secret', 'same password')
    expect(wire1).not.toBe(wire2)
  })
})

describe('decrypt', () => {
  it('round-trips: decrypt(encrypt(secret)) === secret', async () => {
    const plaintext = 'my secret message'
    const password = 'hunter2'
    const wire = await encrypt(plaintext, password)

    // Server stores hex(salt + nonce + ciphertext); reconstruct that blob
    const [saltHex, , encryptedHex] = wire.split('$')
    const hexBlob = saltHex + encryptedHex

    const result = await decrypt(hexBlob, password)
    expect(result).toBe(plaintext)
  })

  it('round-trips with unicode content', async () => {
    const plaintext = 'sécret: 🔐 données sensibles'
    const password = 'p@ssw0rd!'
    const wire = await encrypt(plaintext, password)
    const [saltHex, , encryptedHex] = wire.split('$')
    const hexBlob = saltHex + encryptedHex

    const result = await decrypt(hexBlob, password)
    expect(result).toBe(plaintext)
  })

  it('round-trips with a long secret', async () => {
    const plaintext = 'x'.repeat(10000)
    const password = 'longtest'
    const wire = await encrypt(plaintext, password)
    const [saltHex, , encryptedHex] = wire.split('$')
    const hexBlob = saltHex + encryptedHex

    const result = await decrypt(hexBlob, password)
    expect(result).toBe(plaintext)
  })

  it('throws when decrypting with the wrong password', async () => {
    const wire = await encrypt('secret', 'correctpassword')
    const [saltHex, , encryptedHex] = wire.split('$')
    const hexBlob = saltHex + encryptedHex

    await expect(decrypt(hexBlob, 'wrongpassword')).rejects.toThrow()
  })
})
