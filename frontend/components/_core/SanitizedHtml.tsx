"use client";

import DOMPurify from "dompurify";
import { useEffect, useState } from "react";

/**
 * Renders HTML that comes from the backend, sanitized. DOMPurify needs a
 * DOM, so the content appears only in the browser: sanitizing while
 * rendering on the server throws and takes the whole page's server
 * rendering with it.
 */
export default function SanitizedHtml(props: {
  html: string | null | undefined;
  className?: string;
}) {
  const [sanitized, setSanitized] = useState<string | null>(null);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the DOM exists only after mounting
    setSanitized(DOMPurify.sanitize(props.html || ""));
  }, [props.html]);

  if (sanitized === null) {
    return null;
  }

  return (
    <div
      className={props.className}
      dangerouslySetInnerHTML={{ __html: sanitized }}
    />
  );
}
