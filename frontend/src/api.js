/**
 * @typedef {Object} RetrieveResponse
 * @property {string} encrypted_secret - The encrypted secret payload
 * @property {number} views_remaining  - Number of views remaining after this retrieval
 * @property {number} expires_at       - Unix timestamp when the secret expires
 */

/**
 * Create a new secret in the datastore.
 *
 * Sends a plain-text payload to POST /api/create in the format:
 *   hex(salt)$hex(verifier)$hex(secret)[$ttl[$views]]
 *
 * @param {string} encryptedPayload - hex(salt)$hex(verifier)$hex(nonce‖ciphertext) from encryptSecret()
 * @param {number} ttl   - Time-to-live in seconds
 * @param {number} views - Maximum view count (0 = unlimited)
 * @returns {Promise<string>} The secret ID assigned by the server
 * @throws {Error} On HTTP error or network failure
 */
export async function createSecret(encryptedPayload, ttl, views) {
  const body = views > 0
    ? `${encryptedPayload}$${ttl}$${views}`
    : `${encryptedPayload}$${ttl}`

  const response = await fetch('/api/create', {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain' },
    body,
  })

  if (!response.ok) {
    const contentType = response.headers.get('content-type') ?? ''
    if (contentType.includes('application/json')) {
      const data = await response.json()
      throw new Error(data.error ?? response.statusText)
    }
    throw new Error(response.statusText)
  }

  return response.text()
}

/**
 * Retrieve a secret from the datastore.
 *
 * Sends a JSON request to POST /api/retrieve and returns the parsed response.
 *
 * @param {string} id       - Secret ID returned at creation time
 * @param {string} verifier - Verifier string used to authenticate the request
 * @returns {Promise<RetrieveResponse>} The encrypted secret payload and metadata
 * @throws {Error} On HTTP error or network failure
 */
export async function retrieveSecret(id, verifier) {
  const response = await fetch('/api/retrieve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, verifier }),
  })

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new Error(data.error ?? response.statusText)
  }

  return response.json()
}
