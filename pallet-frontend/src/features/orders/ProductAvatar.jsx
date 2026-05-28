import { useState } from "react";

export default function ProductAvatar({ description, imageUrl, size = "sm" }) {
  const [imgErr, setImgErr] = useState(false);
  const initials = description?.slice(0, 2).toUpperCase() ?? "??";
  const sz = size === "sm" ? "w-9 h-9" : "w-12 h-12";

  if (imageUrl && !imgErr) {
    return (
      <img
        src={imageUrl}
        alt={description}
        onError={() => setImgErr(true)}
        className={`${sz} rounded-lg object-cover shrink-0 bg-gray-100 dark:bg-gray-700`}
      />
    );
  }

  return (
    <div
      className={`${sz} rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0`}
    >
      <span className="text-[10px] font-bold text-gray-400 dark:text-gray-500 leading-none">
        {initials}
      </span>
    </div>
  );
}
