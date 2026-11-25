import type { DeviceType } from './devices'

/** Pictogramme d'un nœud du schéma : type d'appareil, ou Internet et le switch du cœur. */
export type IconKind = DeviceType | 'internet' | 'switch'

// Icônes au trait 24 × 24, dans le style de ui/icons.tsx.
const PATHS: Record<IconKind, string[]> = {
  internet: ['M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20', 'M2 12h20', 'M12 2a15 15 0 0 1 0 20a15 15 0 0 1 0-20'],
  switch: ['M2 8h20v8H2z', 'M6 12h.01', 'M10 12h.01', 'M14 12h.01', 'M18 12h.01'],
  box: ['M2 14h20v8H2z', 'M6 18h.01', 'M10 18h.01', 'M15 10v4', 'M17.8 7.2a4 4 0 0 0-5.6 0', 'M20.7 4.3a8 8 0 0 0-11.3 0'],
  network: ['M5 12.6a11 11 0 0 1 14 0', 'M1.4 9a16 16 0 0 1 21.2 0', 'M8.5 16.1a6 6 0 0 1 7 0', 'M12 20h.01'],
  server: ['M3 3h18v8H3z', 'M3 13h18v8H3z', 'M7 7h.01', 'M7 17h.01'],
  computer: ['M2 3h20v14H2z', 'M8 21h8', 'M12 17v4'],
  laptop: ['M4 5h16v10H4z', 'M2 19h20'],
  phone: ['M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z', 'M12 18h.01'],
  tablet: ['M5 2h14a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z', 'M12 18h.01'],
  tv: ['M2 7h20v13H2z', 'M17 2l-5 5-5-5'],
  printer: ['M6 9V2h12v7', 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2', 'M6 14h12v8H6z'],
  speaker: ['M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z', 'M12 10a4 4 0 1 0 0 8a4 4 0 1 0 0-8', 'M12 6h.01'],
  camera: ['M3 7h4l2-3h6l2 3h4v13H3z', 'M12 10a3.5 3.5 0 1 0 0 7a3.5 3.5 0 1 0 0-7'],
  iot: ['M6 8.3a6 6 0 0 1 0 7.4', 'M9.5 6a10 10 0 0 1 0 12', 'M13 4a14 14 0 0 1 0 16', 'M17 9h3v6h-3z'],
  console: ['M6 8h12a4 4 0 0 1 4 4v1a4 4 0 0 1-7 2.6h-6A4 4 0 0 1 2 13v-1a4 4 0 0 1 4-4z', 'M7 11v3', 'M5.5 12.5h3', 'M16 12h.01', 'M18 13.5h.01'],
  other: ['M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20', 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3', 'M12 17h.01'],
}

export function DeviceIcon({ kind, className = 'size-5' }: { kind: IconKind; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      {PATHS[kind].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  )
}
