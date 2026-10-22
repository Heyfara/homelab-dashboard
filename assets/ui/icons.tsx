import type { ReactNode } from 'react'

// Petites icônes au trait (24 × 24), dans la couleur du texte. Décoratives :
// elles accompagnent toujours un libellé.

function Icon({ children, className = 'size-4' }: { children: ReactNode; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      {children}
    </svg>
  )
}

type P = { className?: string }

export const ArrowDownIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M12 4v16M6 14l6 6 6-6" />
  </Icon>
)
export const ArrowUpIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M12 20V4M6 10l6-6 6 6" />
  </Icon>
)
export const PauseIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M9 5v14M15 5v14" />
  </Icon>
)
export const CheckIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="m5 12.5 4.5 4.5L19 7" />
  </Icon>
)
export const ClockIcon = ({ className }: P) => (
  <Icon className={className}>
    <circle cx="12" cy="12" r="8" />
    <path d="M12 8v4l2.5 2.5" />
  </Icon>
)
export const RefreshIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M19 12a7 7 0 1 1-2.05-4.95M19 5v4h-4" />
  </Icon>
)
export const AlertIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M12 4 2.5 20h19L12 4ZM12 10v4M12 17h.01" />
  </Icon>
)
export const StarIcon = ({ className, filled = false }: P & { filled?: boolean }) => (
  <Icon className={className}>
    <path d="m12 3.5 2.6 5.3 5.9.9-4.25 4.1 1 5.85L12 16.9l-5.25 2.75 1-5.85L3.5 9.7l5.9-.9L12 3.5Z" fill={filled ? 'currentColor' : 'none'} />
  </Icon>
)
export const CoinIcon = ({ className }: P) => (
  <Icon className={className}>
    <circle cx="12" cy="12" r="8" />
    <path d="M9.5 9.5h4.25L9.5 14.5h5" />
  </Icon>
)
export const ServerIcon = ({ className }: P) => (
  <Icon className={className}>
    <rect x="4" y="4" width="16" height="7" rx="1.5" />
    <rect x="4" y="13" width="16" height="7" rx="1.5" />
    <path d="M8 7.5h.01M8 16.5h.01" />
  </Icon>
)
export const ThermometerIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M10 13.5V5a2 2 0 1 1 4 0v8.5a4 4 0 1 1-4 0Z" />
  </Icon>
)
export const CpuIcon = ({ className }: P) => (
  <Icon className={className}>
    <rect x="7" y="7" width="10" height="10" rx="1.5" />
    <path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4" />
  </Icon>
)
export const MemoryIcon = ({ className }: P) => (
  <Icon className={className}>
    <rect x="3" y="7" width="18" height="9" rx="1.5" />
    <path d="M7 16v3M11 16v3M15 16v3M7 10.5h2v2H7zM11 10.5h2v2h-2zM15 10.5h2v2h-2z" />
  </Icon>
)
export const NetworkIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M8 20V5M4 9l4-4 4 4M16 4v15M12 15l4 4 4-4" />
  </Icon>
)
export const PullRequestIcon = ({ className }: P) => (
  <Icon className={className}>
    <circle cx="6" cy="5.5" r="2" />
    <circle cx="6" cy="18.5" r="2" />
    <circle cx="18" cy="18.5" r="2" />
    <path d="M6 7.5v9M18 16.5V9a3 3 0 0 0-3-3h-4M13 3.5 10.5 6 13 8.5" />
  </Icon>
)
export const PackageIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" />
    <path d="m4 7.5 8 4.5 8-4.5M12 12v9" />
  </Icon>
)
export const PlayIcon = ({ className }: P) => (
  <Icon className={className}>
    <path d="M7 4.5v15l12-7.5z" fill="currentColor" />
  </Icon>
)
export const SpeakerIcon = ({ className }: P) => (
  <Icon className={className}>
    <rect x="6" y="3" width="12" height="18" rx="2" />
    <circle cx="12" cy="14" r="3" />
    <path d="M12 7h.01" />
  </Icon>
)
