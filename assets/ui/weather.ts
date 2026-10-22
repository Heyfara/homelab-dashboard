// Codes météo WMO (Open-Meteo) → libellé et icône. Emoji : colorés, lisibles
// par les enfants, sans fichier à charger.

type Weather = { label: string; icon: string }

const CODES: [number[], Weather, Weather?][] = [
  // [codes, jour, nuit si différente]
  [[0], { label: 'Ensoleillé', icon: '☀️' }, { label: 'Ciel dégagé', icon: '🌙' }],
  [[1], { label: 'Plutôt ensoleillé', icon: '🌤️' }, { label: 'Plutôt dégagé', icon: '🌙' }],
  [[2], { label: 'Partiellement nuageux', icon: '⛅' }, { label: 'Partiellement nuageux', icon: '☁️' }],
  [[3], { label: 'Couvert', icon: '☁️' }],
  [[45, 48], { label: 'Brouillard', icon: '🌫️' }],
  [[51, 53, 55], { label: 'Bruine', icon: '🌦️' }],
  [[56, 57, 66, 67], { label: 'Pluie verglaçante', icon: '🌧️' }],
  [[61, 63, 65], { label: 'Pluie', icon: '🌧️' }],
  [[71, 73, 75, 77], { label: 'Neige', icon: '🌨️' }],
  [[80, 81, 82], { label: 'Averses', icon: '🌦️' }],
  [[85, 86], { label: 'Averses de neige', icon: '🌨️' }],
  [[95], { label: 'Orage', icon: '⛈️' }],
  [[96, 99], { label: 'Orage avec grêle', icon: '⛈️' }],
]

export function weather(code: number, isDay: boolean): Weather {
  const match = CODES.find(([codes]) => codes.includes(code))
  if (!match) return { label: 'Météo inconnue', icon: '🌡️' }
  const [, day, night] = match

  return isDay || !night ? day : night
}
