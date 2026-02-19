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
      className={`text-sm transition-colors ${
        active ? 'text-accent-muted' : 'text-content-secondary hover:text-content-primary'
      }`}
    >
      {children}
    </Link>
  )
}

export default function App() {
  return (
    <div className="min-h-screen bg-surface-base text-content-primary">
      <nav className="border-b border-border bg-surface-raised">
        <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-6 px-4 py-4">
          <Link
            href="/"
            className="text-xl font-bold text-content-primary hover:text-accent-muted transition-colors"
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
