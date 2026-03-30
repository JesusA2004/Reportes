import { confirmDialog } from '@/components/feedback/ConfirmDialog'

export function useConfirm() {
  return {
    confirm: confirmDialog,
  }
}
