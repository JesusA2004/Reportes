import { format } from 'date-fns'
import { es } from 'date-fns/locale'

export function useFormatters() {
  const date = (value?: string | null, pattern = 'dd/MM/yyyy') => {
    if (!value) return '—'
    const [year, month, day] = value.split('-').map(Number)
    if (!year || !month || !day) return value
    return format(new Date(year, month - 1, day), pattern, { locale: es })
  }

  const money = (value?: number | string | null, currency = 'MXN') => {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('es-MX', {
      style: 'currency',
      currency,
    }).format(Number(value))
  }

  const number = (value?: number | string | null) => {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('es-MX').format(Number(value))
  }

  return {
    date,
    money,
    number,
  }
}
