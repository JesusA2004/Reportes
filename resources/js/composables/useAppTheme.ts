export function useAppTheme() {
  const isDark = () => document.documentElement.classList.contains('dark')

  const setDark = (value: boolean) => {
    document.documentElement.classList.toggle('dark', value)
  }

  const toggleDark = () => {
    document.documentElement.classList.toggle('dark')
  }

  return {
    isDark,
    setDark,
    toggleDark,
  }
}
