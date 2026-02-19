import { BrowserRouter, Routes, Route, Link, useLocation } from 'react-router-dom'
import CreateSecret from './components/CreateSecret.jsx'
import RetrieveSecret from './components/RetrieveSecret.jsx'

function Nav() {
  const { pathname } = useLocation()
  const isCreate = pathname === '/'
  const isRetrieve = pathname.startsWith('/secret')

  return (
    <header className="border-b border-zinc-800 bg-zinc-950/80 backdrop-blur-sm sticky top-0 z-10">
      <div className="mx-auto max-w-2xl px-4 py-4 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-2 group">
          <span className="font-mono text-cipher-500 text-lg font-semibold tracking-tight group-hover:text-cipher-400 transition-colors">
            ⚔ swordfish
          </span>
        </Link>
        <nav className="flex gap-1">
          <Link
            to="/"
            className={`px-3 py-1.5 rounded-md font-sans text-sm transition-colors ${
              isCreate
                ? 'bg-zinc-800 text-zinc-100'
                : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/50'
            }`}
          >
            Create
          </Link>
          <Link
            to="/secret"
            className={`px-3 py-1.5 rounded-md font-sans text-sm transition-colors ${
              isRetrieve
                ? 'bg-zinc-800 text-zinc-100'
                : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/50'
            }`}
          >
            Retrieve
          </Link>
        </nav>
      </div>
    </header>
  )
}

function Footer() {
  return (
    <footer className="border-t border-zinc-800 mt-auto">
      <div className="mx-auto max-w-2xl px-4 py-4">
        <p className="font-mono text-xs text-zinc-600 text-center">
          &copy; 2025{' '}
          <a
            href="https://eamann.com"
            className="text-zinc-500 hover:text-zinc-300 transition-colors"
          >
            Eric Mann
          </a>
          {' '}— end-to-end encrypted, zero-knowledge secret sharing
        </p>
      </div>
    </footer>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <div className="flex flex-col min-h-screen bg-zinc-950">
        <Nav />
        <main className="flex-1 mx-auto w-full max-w-2xl px-4 py-10">
          <Routes>
            <Route path="/" element={<CreateSecret />} />
            <Route path="/secret" element={<RetrieveSecret />} />
            <Route path="/secret/:secretId" element={<RetrieveSecret />} />
          </Routes>
        </main>
        <Footer />
      </div>
    </BrowserRouter>
  )
}
