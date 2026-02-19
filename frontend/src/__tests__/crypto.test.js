import { describe, it, expect } from 'vitest'
import { encrypt, decrypt } from '../crypto.js'

const HEX_RE = /^[0-9a-f]+$/

describe('encrypt', () => {
  it('returns a creation string with three $-delimited segments', async () => {
    const result = await encrypt('my secret', 'my passphrase')
    const parts = result.split('$')
    expect(parts).toHaveLength(3)
  })

  it('encodes salt as 32 hex chars (16 bytes)', async () => {
    const [saltHex] = (await encrypt('s', 'p')).split('$')
    expect(saltHex).toHaveLength(32)
    expect(HEX_RE.test(saltHex)).toBe(true)
  })

  it('encodes verifier as 64 hex chars (256 bits)', async () => {
    const [, verifierHex] = (await encrypt('s', 'p')).split('$')
    expect(verifierHex).toHaveLength(64)
    expect(HEX_RE.test(verifierHex)).toBe(true)
  })

  it('encodes payload starting with 24 hex chars nonce (12 bytes)', async () => {
    const [, , payload] = (await encrypt('s', 'p')).split('$')
    const nonceHex = payload.slice(0, 24)
    expect(nonceHex).toHaveLength(24)
    expect(HEX_RE.test(nonceHex)).toBe(true)
  })

  it('produces a different ciphertext on each call (random salt/nonce)', async () => {
    const a = await encrypt('same secret', 'same passphrase')
    const b = await encrypt('same secret', 'same passphrase')
    expect(a).not.toBe(b)
  })

  it('derives the same verifier for the same passphrase', async () => {
    const [, verifierA] = (await encrypt('secret1', 'passphrase')).split('$')
    const [, verifierB] = (await encrypt('secret2', 'passphrase')).split('$')
    expect(verifierA).toBe(verifierB)
  })

  it('derives different verifiers for different passphrases', async () => {
    const [, verifierA] = (await encrypt('secret', 'passphrase1')).split('$')
    const [, verifierB] = (await encrypt('secret', 'passphrase2')).split('$')
    expect(verifierA).not.toBe(verifierB)
  })
})

describe('decrypt', () => {
  it('round-trips: decrypt recovers the original secret', async () => {
    const secret = 'hello, world!'
    const passphrase = 'correct horse battery staple'

    const creationString = await encrypt(secret, passphrase)
    const [saltHex, , payload] = creationString.split('$')

    // Server-stored format: hex(salt) + hex(nonce) + hex(ciphertext)
    const serverPayload = saltHex + payload

    const recovered = await decrypt(serverPayload, passphrase)
    expect(recovered).toBe(secret)
  })

  it('round-trips with unicode secrets', async () => {
    const secret = '🔐 secret café'
    const passphrase = 'pässwörд'

    const creationString = await encrypt(secret, passphrase)
    const [saltHex, , payload] = creationString.split('$')
    const recovered = await decrypt(saltHex + payload, passphrase)
    expect(recovered).toBe(secret)
  })

  it('throws when decrypting with the wrong passphrase', async () => {
    const creationString = await encrypt('secret', 'correct-passphrase')
    const [saltHex, , payload] = creationString.split('$')
    await expect(decrypt(saltHex + payload, 'wrong-passphrase')).rejects.toThrow()
  })
})
