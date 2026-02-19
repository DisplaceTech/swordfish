/**
 * @typedef {Object} RetrieveResponse
 * @property {string} encrypted_secret - The encrypted secret payload
 * @property {number} views_remaining  - Number of views remaining after this retrieval (-1 = unlimited)
 * @property {number} expires_at       - Unix timestamp when the secret expires
 */

/**
 * Create a new secret in the datastore.
 *
 * Splits the encryptedPayload (hex(salt)$hex(verifier)$hex(nonce‖ciphertext)) into its
 * components and sends a JSON body to POST /api/create.
 *
 * @param {string} encryptedPayload - hex(salt)$hex(verifier)$hex(nonce‖ciphertext) from encryptSecret()
 * @param {number} ttl   - Time-to-live in seconds
 * @param {number} views - Maximum view count (0 = unlimited)
 * @returns {Promise<string>} The secret ID assigned by the server
 * @throws {Error} On HTTP error or network failure
 */
export async function createSecret(encryptedPayload, ttl, views) {
  const [salt, verifier, secret] = encryptedPayload.split('$')

  const response = await fetch('/api/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ salt, verifier, secret, ttl, views }),
  })

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new Error(data.error ?? response.statusText)
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
