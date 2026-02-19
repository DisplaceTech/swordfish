import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

describe('createSecret payload serialisation', () => {
  let fetchMock

  beforeEach(() => {
    fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      text: async () => 'abc123',
    })
    globalThis.fetch = fetchMock
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('sends hex(salt)$hex(verifier)$hex(payload)$ttl when views is 0', async () => {
    const { createSecret } = await import('../api.js')
    await createSecret('aaa$bbb$ccc', 3600, 0)
    const body = fetchMock.mock.calls[0][1].body
    expect(body).toBe('aaa$bbb$ccc$3600')
  })

  it('appends $views when views > 0', async () => {
    const { createSecret } = await import('../api.js')
    await createSecret('aaa$bbb$ccc', 86400, 5)
    const body = fetchMock.mock.calls[0][1].body
    expect(body).toBe('aaa$bbb$ccc$86400$5')
  })

  it('returns the secret ID from the response', async () => {
    const { createSecret } = await import('../api.js')
    const id = await createSecret('aaa$bbb$ccc', 3600, 0)
    expect(id).toBe('abc123')
  })

  it('throws on non-OK response with JSON error', async () => {
    fetchMock.mockResolvedValueOnce({
      ok: false,
      headers: { get: () => 'application/json' },
      json: async () => ({ error: 'Payload Too Large' }),
      statusText: 'Payload Too Large',
    })
    const { createSecret } = await import('../api.js')
    await expect(createSecret('aaa$bbb$ccc', 3600, 0)).rejects.toThrow('Payload Too Large')
  })
})
