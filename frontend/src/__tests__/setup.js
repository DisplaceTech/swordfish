import '@testing-library/jest-dom'

// jsdom does not implement window.matchMedia; provide a minimal stub so
// components that call it (e.g. EncryptionFlow) don't throw in tests.
// Guard against non-browser environments (e.g. node environment for crypto tests).
if (typeof window !== 'undefined') {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }),
  })
}
