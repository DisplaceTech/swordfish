import { describe, it, expect } from 'vitest'
import { encrypt, decrypt } from '../crypto.js'

const HEX_RE = /^[0-9a-f]+$/

// Known test vectors computed with the same PBKDF2/AES-256-GCM parameters as v1.
// Salt and nonce are fixed (non-random) so the output is deterministic.
const VECTORS = {
  ascii: {
    secret: 'hello world',
    passphrase: 'test-passphrase',
    // salt: 16 zero bytes, nonce: 12 zero bytes
    serverPayload:
      '00000000000000000000000000000000' +
      '000000000000000000000000' +
      '047d9c0a06dc03c270c4ae974eef9c520ecb73c994aaf4bb35c85c',
    verifier: 'a0ad802be50bfc11b86b70122ed523b2eb91213a5d0994bd3f7c8ee526439edc',
  },
  unicode: {
    secret: '🔐 secret café',
    passphrase: 'pässwörд',
    // salt: 16 bytes of 0x01, nonce: 12 bytes of 0x02
    serverPayload:
      '01010101010101010101010101010101' +
      '020202020202020202020202' +
      '18053b9503ec36b4f8a905e08ab6016ec45d8b5e597029ae601124b35635795e74',
    verifier: '45812db5d0359e6d987fed0632dbfeb7d0460a2962d01d37623034084f46874c',
  },
}

describe('encrypt — output format', () => {
  it('returns a creation string with exactly three $-delimited segments', async () => {
    const result = await encrypt('my secret', 'my passphrase')
    expect(result.split('$')).toHaveLength(3)
  })

  it('encodes salt as 32 lowercase hex chars (16 bytes)', async () => {
    const [saltHex] = (await encrypt('s', 'p')).split('$')
    expect(saltHex).toHaveLength(32)
    expect(HEX_RE.test(saltHex)).toBe(true)
  })

  it('encodes verifier as 64 lowercase hex chars (256 bits)', async () => {
    const [, verifierHex] = (await encrypt('s', 'p')).split('$')
    expect(verifierHex).toHaveLength(64)
    expect(HEX_RE.test(verifierHex)).toBe(true)
  })

  it('encodes payload with a 24-hex-char nonce prefix (12 bytes) followed by ciphertext', async () => {
    const [, , payload] = (await encrypt('s', 'p')).split('$')
    const nonceHex = payload.slice(0, 24)
    expect(nonceHex).toHaveLength(24)
    expect(HEX_RE.test(nonceHex)).toBe(true)
    // ciphertext portion must also be valid hex
    const ciphertextHex = payload.slice(24)
    expect(ciphertextHex.length).toBeGreaterThan(0)
    expect(HEX_RE.test(ciphertextHex)).toBe(true)
  })
})

describe('encrypt — verifier derivation', () => {
  it('derives the same verifier for the same passphrase regardless of secret', async () => {
    const [, verifierA] = (await encrypt('secret1', 'passphrase')).split('$')
    const [, verifierB] = (await encrypt('secret2', 'passphrase')).split('$')
    expect(verifierA).toBe(verifierB)
  })

  it('derives different verifiers for different passphrases', async () => {
    const [, verifierA] = (await encrypt('secret', 'passphrase1')).split('$')
    const [, verifierB] = (await encrypt('secret', 'passphrase2')).split('$')
    expect(verifierA).not.toBe(verifierB)
  })

  it('matches the v1 known verifier for the ASCII test vector passphrase', async () => {
    const { passphrase, verifier } = VECTORS.ascii
    const [, verifierHex] = (await encrypt('any secret', passphrase)).split('$')
    expect(verifierHex).toBe(verifier)
  })

  it('matches the v1 known verifier for the unicode test vector passphrase', async () => {
    const { passphrase, verifier } = VECTORS.unicode
    const [, verifierHex] = (await encrypt('any secret', passphrase)).split('$')
    expect(verifierHex).toBe(verifier)
  })
})

describe('encrypt — randomness', () => {
  it('produces a different creation string on each call (random salt + nonce)', async () => {
    const a = await encrypt('same secret', 'same passphrase')
    const b = await encrypt('same secret', 'same passphrase')
    expect(a).not.toBe(b)
  })
})

describe('decrypt — round-trip', () => {
  it('recovers the original ASCII secret', async () => {
    const secret = 'hello, world!'
    const passphrase = 'correct horse battery staple'
    const [saltHex, , payload] = (await encrypt(secret, passphrase)).split('$')
    expect(await decrypt(saltHex + payload, passphrase)).toBe(secret)
  })

  it('recovers a unicode secret with a unicode passphrase', async () => {
    const secret = '🔐 secret café'
    const passphrase = 'pässwörд'
    const [saltHex, , payload] = (await encrypt(secret, passphrase)).split('$')
    expect(await decrypt(saltHex + payload, passphrase)).toBe(secret)
  })
})

describe('decrypt — known v1 test vectors', () => {
  it('decrypts the ASCII v1 test vector to the expected plaintext', async () => {
    const { secret, passphrase, serverPayload } = VECTORS.ascii
    expect(await decrypt(serverPayload, passphrase)).toBe(secret)
  })

  it('decrypts the unicode v1 test vector to the expected plaintext', async () => {
    const { secret, passphrase, serverPayload } = VECTORS.unicode
    expect(await decrypt(serverPayload, passphrase)).toBe(secret)
  })
})

describe('decrypt — error cases', () => {
  it('throws when decrypting with the wrong passphrase', async () => {
    const [saltHex, , payload] = (await encrypt('secret', 'correct-passphrase')).split('$')
    await expect(decrypt(saltHex + payload, 'wrong-passphrase')).rejects.toThrow()
  })

  it('throws when the server payload is truncated (missing ciphertext bytes)', async () => {
    // Provide only salt + nonce with no ciphertext — AES-GCM will reject the auth tag
    const truncated = '00000000000000000000000000000000' + '000000000000000000000000'
    await expect(decrypt(truncated, 'any-passphrase')).rejects.toThrow()
  })
})
