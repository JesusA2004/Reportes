import { toast } from '@/components/feedback/Toast'

export function useToast() {
  return {
    success(title: string) {
      return toast.fire({ icon: 'success', title })
    },
    error(title: string) {
      return toast.fire({ icon: 'error', title })
    },
    info(title: string) {
      return toast.fire({ icon: 'info', title })
    },
    warning(title: string) {
      return toast.fire({ icon: 'warning', title })
    },
  }
}
