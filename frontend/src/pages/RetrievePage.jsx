import { useParams } from 'react-router-dom'

export default function RetrievePage() {
  const { secretId } = useParams()

  return (
    <div>
      <h2 className="text-2xl font-semibold mb-4">Retrieve a Secret</h2>
      {secretId ? (
        <p className="text-gray-400">Retrieving secret: <span className="font-mono text-gray-200">{secretId}</span></p>
      ) : (
        <p className="text-gray-400">Enter a secret ID to retrieve it.</p>
      )}
    </div>
  )
}
