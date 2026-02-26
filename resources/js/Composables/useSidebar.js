
import { ref } from 'vue'

const sidebarExpandido = ref(false) 

export function useSidebar() {
  const toggleSidebar = () => {
    sidebarExpandido.value = !sidebarExpandido.value
  }

  return {
    sidebarExpandido,
    toggleSidebar
  }
}
