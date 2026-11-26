/**
 * The text of an HTML fragment (a notification body may hold a list), for
 * showing it as plain text. Browser only: DOMParser runs no scripts and
 * loads nothing while parsing.
 */
export function htmlToText(html: string | null | undefined): string {
  if (!html) {
    return "";
  }

  const document = new DOMParser().parseFromString(html, "text/html");

  // Items of a list would otherwise run together.
  document.querySelectorAll("li, p, br").forEach((element) => {
    element.append(" ");
  });

  return (document.body.textContent ?? "").replace(/\s+/g, " ").trim();
}

/**
 * Whether an address from the backend may become a link: only http(s).
 */
export function isWebAddress(url: unknown): url is string {
  return typeof url === "string" && /^https?:\/\//i.test(url);
}
