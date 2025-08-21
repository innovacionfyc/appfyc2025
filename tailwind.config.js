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
          negro: '#121212',
          negro_opacity_medio: '#1515153a',
          negro_opacity_full: '#151515be',
        },
        bg: {
          empty: '#1A2130',
        },
        primary: { 
          vinotinto: '#942934',
          verde: '#685F2F',
          naranja: '#E96510',
          gris: '#36474F',
          toronja: '#CCC715'
        },
        secondary: {
          vinotinto2: '#D32F57',
          verde2: '#A08E43',
          naranja2: '#F39322',
          gris2: '#58636D',
          toronja2: '#9B9900'
        },
        gradient: {
          vinotinto: 'bg-gradient-to-r from-[#D32F57] to-[#942934]',
          verde: 'bg-gradient-to-r from-[#A08E43] to-[#685F2F]',
          naranja: 'bg-gradient-to-r from-[#F39322] to-[#E96510]',
          gris: 'bg-gradient-to-r from-[#58636D] to-[#36474F]',
          toronja: 'bg-gradient-to-r from-[#9B9900] to-[#CCC715]'
        },
        semaforo: {
          verde: '#16C47F',
          amarillo: '#FCC61D',
          rojo: '#FF3131',
          verde_opacity: '#1bcc763f',
          rojo_opacity: '#ff31312d',
          amarillo_opacity: '#ffa8233f'
        },
        extra: {
          default: '#030637',
          light: '#98A1BC',
          opacity: 'rgba(26, 33, 48, 0.479)'
        }
      }
    },
  },
  plugins: [],
}
