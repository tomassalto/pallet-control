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
  size = "lg",
  className = "",
  as: Component = "h1",
}) {
  const sizeClass = SIZE_CLASSES[size] || SIZE_CLASSES.lg;

  return (
    <Component
      className={clsx(
        " font-['Montserrat'] font-semibold text-center",
        sizeClass,
        className,
      )}
    >
      {children}
    </Component>
  );
}
