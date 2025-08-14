module.exports = {
    content: [
      "./resources/**/*.blade.php",
      "./resources/**/*.js",
      "./resources/**/*.vue",
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                mono: {
                  blanco: '#F5F7F8',
                  blanco_opacity: '#f5f7f873',
                  negro: '#151515',
                  negro_opacity_medio: '#1515153a',
                  negro_opacity_full: '#151515be',
                },
                bg: {
                  empty: '#1A2130',
                },
                primary: 'var(--color-primary)',
                secondary: 'var(--color-secondary)',
                universal:{
                  naranja: '#f05235',
                  naranja_opacity: '#fe502d36',
                  azul: '#3652AD',
                  azul_opacity: '#3652AD3A',
                  azul_secundaria: '#83B4FF'
                },
                semaforo: {
                  verde: '#1BCC75',
                  amarillo: '#FFA823',
                  rojo: '#FF3131',
                  verde_opacity: '#1bcc763f',
                  rojo_opacity : '#ff31312d',
                  amarillo_opacity: '#ffa8233f'
                },
                secundary: {
                  default: '#030637',
                  light: '#BFCFE7',
                  opacity: 'rgba(26, 33, 48, 0.479)'
                }
            }
        },
    },
    plugins: [],
  }
  