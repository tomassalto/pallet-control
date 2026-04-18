import clsx from "clsx";

const SIZE_CLASSES = {
  sm: "px-3 py-2 text-sm",
  md: "px-4 py-3 text-sm",
  lg: "px-4 py-3 text-base",
};

const COLOR_CLASSES = {
  black: "bg-black text-white",
  gray: "bg-gray-100 text-gray-900",
  white: "bg-white text-gray-900 border",
  red: "bg-red-600 text-white",
};

export default function Button({
  text,
  size = "md",
  color = "black",
  className = "",
  disabled,
  ...props
}) {
  const sizeClass = SIZE_CLASSES[size] || SIZE_CLASSES.md;
  const colorClass = COLOR_CLASSES[color] || COLOR_CLASSES.black;

  return (
    <button
      disabled={disabled}
      className={clsx(
        "inline-flex items-center justify-center rounded-lg font-medium disabled:opacity-60 active:scale-[0.99] transition-transform",
        sizeClass,
        colorClass,
        className
      )}
      {...props}
    >
      {text}
    </button>
  );
}
