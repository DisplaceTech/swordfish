import { useState, useEffect, useRef } from 'preact/hooks'

const STEPS = [
  {
    id: 'secret',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line x1="16" y1="13" x2="8" y2="13" />
        <line x1="16" y1="17" x2="8" y2="17" />
        <polyline points="10 9 9 9 8 9" />
      </svg>
    ),
    label: 'Your Secret',
    detail: 'Plaintext in your browser',
  },
  {
    id: 'encrypt',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
      </svg>
    ),
    label: 'Encrypted',
    detail: 'AES-256-GCM, in browser',
  },
  {
    id: 'store',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true">
        <ellipse cx="12" cy="5" rx="9" ry="3" />
        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3" />
        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" />
      </svg>
    ),
    label: 'Stored',
    detail: 'Ciphertext only, on server',
  },
  {
    id: 'decrypt',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 9.9-1" />
      </svg>
    ),
    label: 'Decrypted',
    detail: "Recipient's browser only",
  },
  {
    id: 'destroy',
    icon: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true">
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        <path d="M10 11v6" />
        <path d="M14 11v6" />
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
      </svg>
    ),
    label: 'Destroyed',
    detail: 'After view limit reached',
  },
]

const STEP_DURATION = 1600 // ms per step

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function ArrowRight() {
  return (
    <div className="flow-arrow-h hidden shrink-0 items-center justify-center sm:flex" aria-hidden="true">
      <svg viewBox="0 0 24 8" className="h-2 w-8 text-gray-600" fill="none">
        <path d="M0 4h20M16 1l4 3-4 3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </div>
  )
}

function ArrowDown() {
  return (
    <div className="flex items-center justify-center sm:hidden" aria-hidden="true">
      <svg viewBox="0 0 8 24" className="h-8 w-2 text-gray-600" fill="none">
        <path d="M4 0v20M1 16l3 4 3-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </div>
  )
}

function StepNode({ step, isActive, reducedMotion }) {
  const activeClass = isActive || reducedMotion
    ? 'border-indigo-500 bg-indigo-950 text-indigo-300 shadow-[0_0_12px_2px_rgba(99,102,241,0.35)]'
    : 'border-gray-800 bg-gray-900 text-gray-500'

  return (
    <div
      className={`flow-step flex flex-1 flex-col items-center gap-2 rounded-xl border p-4 text-center transition-all duration-500 ${activeClass}`}
      aria-current={isActive ? 'step' : undefined}
    >
      <div className={`transition-colors duration-500 ${isActive || reducedMotion ? 'text-indigo-400' : 'text-gray-600'}`}>
        {step.icon}
      </div>
      <p className={`text-xs font-semibold leading-tight transition-colors duration-500 ${isActive || reducedMotion ? 'text-indigo-200' : 'text-gray-500'}`}>
        {step.label}
      </p>
      <p className={`text-xs leading-tight transition-colors duration-500 ${isActive || reducedMotion ? 'text-indigo-300/70' : 'text-gray-600'}`}>
        {step.detail}
      </p>
    </div>
  )
}

export default function EncryptionFlow() {
  const [activeStep, setActiveStep] = useState(0)
  const reducedMotion = prefersReducedMotion()
  const intervalRef = useRef(null)

  useEffect(() => {
    if (reducedMotion) return

    intervalRef.current = setInterval(() => {
      setActiveStep(s => (s + 1) % STEPS.length)
    }, STEP_DURATION)

    return () => clearInterval(intervalRef.current)
  }, [reducedMotion])

  return (
    <section aria-label="End-to-end encryption flow" className="mb-8">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-stretch sm:gap-1">
        {STEPS.map((step, i) => (
          <div key={step.id} className="contents">
            <StepNode
              step={step}
              isActive={!reducedMotion && activeStep === i}
              reducedMotion={reducedMotion}
            />
            {i < STEPS.length - 1 && (
              <>
                <ArrowRight />
                <ArrowDown />
              </>
            )}
          </div>
        ))}
      </div>
      <p className="mt-3 text-center text-xs text-gray-600">
        Your secret never leaves your browser in plaintext.
      </p>
    </section>
  )
}
