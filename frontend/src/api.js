/**
 * @typedef {Object} CreateResponse
 * @property {string} id         - Compound "secretID:verifier" string; split on ':' for the retrieve call
 * @property {number} expires_at - Unix timestamp when the secret expires
 * @property {number} max_views  - Maximum number of retrieval views
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
 * Sends a JSON payload to POST /api/create:
 *   {"encrypted_secret": "...", "ttl": 86400, "max_views": 1}
 *
 * The returned `id` is a compound "secretID:verifier" string. Split on ':'
 * to obtain the secretID and verifier needed for the retrieve endpoint.
 *
 * @param {string} encryptedSecret - Client-side encrypted secret payload
 * @param {number} [ttl=86400]     - Secret lifetime in seconds (1–604800)
 * @param {number} [maxViews=1]    - Maximum number of retrieval views
 * @returns {Promise<CreateResponse>} The created secret metadata
 * @throws {Error} On HTTP error or network failure
 */
export async function createSecret(encryptedSecret, ttl = 86400, maxViews = 1) {
  const response = await fetch('/api/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ encrypted_secret: encryptedSecret, ttl, max_views: maxViews }),
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
