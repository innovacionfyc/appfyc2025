// resources/js/Composables/useConfirmationModal.js

import { ref } from 'vue';

export function useConfirmationModal() {
  const confirmationState = ref({
    isOpen: false,
    title: "",
    message: "",
    icon: "help",
    iconBgClass: "bg-gray-700",
    confirmText: "Confirmar",
    onConfirm: () => {},
  });

  function openConfirmationModal(config) {
    confirmationState.value = { ...confirmationState.value, ...config, isOpen: true };
  }

  function closeConfirmationModal() {
    confirmationState.value.isOpen = false;
  }

  function handleConfirm() {
    if (typeof confirmationState.value.onConfirm === 'function') {
      confirmationState.value.onConfirm();
    }
    closeConfirmationModal();
  }

  return {
    confirmationState,
    openConfirmationModal,
    closeConfirmationModal,
    handleConfirm,
  };
}