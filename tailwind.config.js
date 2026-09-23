import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.vue',
  ],

  theme: {
    screens: {
      xs: '390px',
      ...defaultTheme.screens,
    },
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        mono: {
          blanco: '#F5F7F8',
          blanco_opacity: '#f5f7f873',
          negro: '#070910',
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
        },
        // Sub-identidad del podcast "Íntimamente Hablando".
        // acento no cumple contraste como texto pequeño sobre blanco: usarlo en rellenos,
        // puntos, reglas y hovers, o con texto podcast-oscuro encima.
        podcast: {
          oscuro: '#3f4e54',
          acento: '#c2c027',
        }
      },
      backgroundImage: {
        'gradient-vinotinto': 'linear-gradient(to right, #D32F57, #942934)',
        'gradient-verde': 'linear-gradient(to right, #A08E43, #685F2F)',
        'gradient-naranja': 'linear-gradient(to right, #F39322, #E96510)',
        'gradient-gris': 'linear-gradient(to right, #58636D, #36474F)',
        'gradient-toronja': 'linear-gradient(to right, #9B9900, #CCC715)',
      },
      animation: {
        blob: "blob 7s infinite",
      },
      keyframes: {
        blob: {
          "0%": { transform: "translate(0px, 0px) scale(1)" },
          "33%": { transform: "translate(30px, -50px) scale(1.1)" },
          "66%": { transform: "translate(-20px, 20px) scale(0.9)" },
          "100%": { transform: "translate(0px, 0px) scale(1)" },
        },
      },

    },
  },

  plugins: [forms],
};