/**
 * @typedef {Object} CreatePayload
 * @property {string} encrypted_secret - Client-side encrypted payload: hex(salt)$hex(verifier)$hex(secret)
 * @property {number} ttl              - Secret lifetime in seconds (1-604800)
 * @property {number} max_views        - Maximum retrieval count (0 = unlimited; allowed: 0, 1, 3, 5, 10)
 */

/**
 * @typedef {Object} CreateResponse
 * @property {string} id         - The generated secret ID
 * @property {number} expires_at - Unix timestamp when the secret expires
 * @property {number} max_views  - Maximum retrieval count (0 = unlimited)
 */

/**
 * @typedef {Object} RetrieveResponse
 * @property {string} encrypted_secret - The encrypted secret payload
 * @property {number} views_remaining  - Number of views remaining after this retrieval
 * @property {number} expires_at       - Unix timestamp when the secret expires
 */

/**
 * Create a new secret in the datastore.
 *
 * Sends a JSON payload to POST /api/create.
 *
 * @param {CreatePayload} payload - Secret creation request
 * @returns {Promise<CreateResponse>} The created secret metadata
 * @throws {Error} On HTTP error or network failure
 */
export async function createSecret(payload) {
  const response = await fetch('/api/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new Error(data.message ?? data.error ?? response.statusText)
  }

  return response.json()
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
