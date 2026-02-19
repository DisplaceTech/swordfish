import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createSecret } from '../api.js'

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

  it('sends JSON with salt/verifier/secret/ttl/views fields', async () => {
    await createSecret('aaa$bbb$ccc', 3600, 0)
    const body = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(body).toEqual({ salt: 'aaa', verifier: 'bbb', secret: 'ccc', ttl: 3600, views: 0 })
  })

  it('includes views in JSON body when views > 0', async () => {
    await createSecret('aaa$bbb$ccc', 86400, 5)
    const body = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(body).toEqual({ salt: 'aaa', verifier: 'bbb', secret: 'ccc', ttl: 86400, views: 5 })
  })

  it('sends Content-Type: application/json', async () => {
    await createSecret('aaa$bbb$ccc', 3600, 0)
    const headers = fetchMock.mock.calls[0][1].headers
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('returns the secret ID from the response', async () => {
    const id = await createSecret('aaa$bbb$ccc', 3600, 0)
    expect(id).toBe('abc123')
  })

  it('throws on non-OK response with JSON error', async () => {
    fetchMock.mockResolvedValueOnce({
      ok: false,
      json: async () => ({ error: 'Payload Too Large' }),
      statusText: 'Payload Too Large',
    })
    await expect(createSecret('aaa$bbb$ccc', 3600, 0)).rejects.toThrow('Payload Too Large')
  })
})
