import { useState, useEffect } from 'preact/hooks'
import About from './About.jsx'

function getHash() {
  return window.location.hash.replace(/^#\/?/, '') || 'home'
}

export default function App() {
  const [page, setPage] = useState(getHash)

  useEffect(() => {
    const onHashChange = () => setPage(getHash())
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [])

  return (
    <div className="min-h-screen bg-gray-950 text-gray-100">
      <nav className="border-b border-gray-800 bg-gray-900">
        <div className="mx-auto flex max-w-3xl items-center gap-6 px-4 py-4">
          <a
            href="#home"
            className="text-xl font-bold text-gray-100 hover:text-indigo-400 transition-colors"
          >
            Swordfish
          </a>
          <a
            href="#about"
            className={`text-sm transition-colors ${
              page === 'about'
                ? 'text-indigo-400'
                : 'text-gray-400 hover:text-gray-100'
            }`}
          >
            How It Works
          </a>
        </div>
      </nav>

      {page === 'about' ? (
        <About />
      ) : (
        <div className="flex min-h-[calc(100vh-57px)] items-center justify-center">
          <h1 className="text-4xl font-bold">Swordfish</h1>
        </div>
      )}
    </div>
  )
}
