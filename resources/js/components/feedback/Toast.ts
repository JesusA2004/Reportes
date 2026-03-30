import Swal from 'sweetalert2'

export const toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 2800,
  timerProgressBar: true,
  buttonsStyling: false,
  customClass: {
    popup: 'rounded-2xl border border-border bg-card text-card-foreground shadow-xl',
    title: 'text-sm font-semibold',
  },
})
