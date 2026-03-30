import Swal from 'sweetalert2'

export async function confirmDialog(options?: {
  title?: string
  text?: string
  icon?: 'warning' | 'question' | 'info' | 'success' | 'error'
  confirmText?: string
  cancelText?: string
}) {
  return Swal.fire({
    title: options?.title ?? '¿Estás seguro?',
    text: options?.text ?? 'Esta acción puede modificar información.',
    icon: options?.icon ?? 'warning',
    showCancelButton: true,
    confirmButtonText: options?.confirmText ?? 'Sí, continuar',
    cancelButtonText: options?.cancelText ?? 'Cancelar',
    reverseButtons: true,
    buttonsStyling: false,
    customClass: {
      popup: 'rounded-[28px] border border-border bg-card text-card-foreground shadow-2xl',
      title: 'text-xl font-bold text-foreground',
      htmlContainer: 'text-sm text-muted-foreground',
      actions: 'gap-3',
      confirmButton: 'app-btn app-btn-primary',
      cancelButton: 'app-btn app-btn-secondary',
    },
  })
}
