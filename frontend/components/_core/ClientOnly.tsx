"use client";

import { ReactNode, useEffect, useState } from "react";

/**
 * Renders its children only in the browser, for client components that use
 * browser-only libraries (e.g. DOMPurify) already while rendering.
 */
export default function ClientOnly(props: { children: ReactNode }) {
  const [isMounted, setIsMounted] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- mounting is the whole point
    setIsMounted(true);
  }, []);

  return isMounted ? props.children : null;
}
