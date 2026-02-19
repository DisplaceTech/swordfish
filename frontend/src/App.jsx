import Router, { Link, useRouter } from 'preact-router'
import Create from './Create.jsx'
import Retrieve from './Retrieve.jsx'
import About from './About.jsx'

function NavLink({ href, children }) {
  const [{ url }] = useRouter()
  const active = url === href
  return (
    <Link
      href={href}
      className={`py-3 text-sm transition-colors ${
        active ? 'text-indigo-400' : 'text-gray-400 hover:text-gray-100'
      }`}
    >
      {children}
    </Link>
  )
}

export default function App() {
  return (
    <div className="min-h-screen bg-gray-950 text-gray-100">
      <nav className="border-b border-gray-800 bg-gray-900">
        <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-6 px-4 py-4">
          <Link
            href="/"
            className="text-xl font-bold text-gray-100 hover:text-indigo-400 transition-colors"
          >
            Swordfish
          </Link>
          <NavLink href="/">Create</NavLink>
          <NavLink href="/secret">Retrieve</NavLink>
          <NavLink href="/about">How It Works</NavLink>
        </div>
      </nav>

      <Router>
        <Create path="/" />
        <Retrieve path="/secret" />
        <Retrieve path="/secret/:id" />
        <About path="/about" />
      </Router>
    </div>
  )
}
