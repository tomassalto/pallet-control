import { useEffect } from "react";
import { useLocation } from "react-router-dom";

/**
 * Scroll to the top of the page on every route change.
 * Fixes mobile: navigating between pages no longer inherits the previous scroll position.
 */
export default function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);
  return null;
}
