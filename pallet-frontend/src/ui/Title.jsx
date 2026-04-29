import clsx from "clsx";

const SIZE_CLASSES = {
  sm: "text-sm",
  md: "text-base",
  lg: "text-lg",
  xl: "text-xl",
  "2xl": "text-2xl",
  "3xl": "text-3xl",
  "4xl": "text-4xl",
  "5xl": "text-5xl",
  "6xl": "text-6xl",
  "7xl": "text-7xl",
  "8xl": "text-8xl",
  "9xl": "text-9xl",
  "10xl": "text-10xl",
};

export default function Title({
  children,
  size = "",
  className = "text-4xl lg:text-5xl",
  as: Component = "h1",
}) {
  // size prop solo aplica si se pasa explícitamente; si no, className controla el tamaño
  const sizeClass = size ? SIZE_CLASSES[size] : "";

  return (
    <Component
      className={clsx(
        "font-['Montserrat'] font-semibold text-center",
        sizeClass,
        className,
      )}
    >
      {children}
    </Component>
  );
}
