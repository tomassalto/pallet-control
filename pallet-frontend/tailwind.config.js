/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{js,jsx}"],
  theme: {
    extend: {
      fontFamily: {
        montserrat: ["Montserrat", "sans-serif"],
      },
      colors: {
        gold: "#BF953F",
        yellow: "#FFA800",
        light_gold: "#B59E57",
        ultra_dark_gray: "#0e0d0d",
        dark_gray_3: "#191919",
        dark_gray_2: "#1c1c1c",
        dark_gray: "#111010",
        medium_gray: "#2B2B2B",
        gray: "#999999",
        light_gray: "#EAEAEA",
        black: "#000000",
        light_gray_2: "#EFEFF1",
      },
    },
  },
  plugins: [],
};
