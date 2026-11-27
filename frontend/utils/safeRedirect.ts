const ORIGIN = "http://molmedb.invalid";

/**
 * Guards against open-redirect attacks: only allows paths on this site
 * (e.g. "/lab"). Rejects absolute URLs, protocol-relative URLs
 * ("//evil.com"), anything containing a scheme ("javascript:", "https:"),
 * backslashes and control characters: browsers read a backslash as a slash
 * and drop tabs and newlines, so "/\t/evil.com" would lead to evil.com.
 *
 * Returns the path as the URL parser normalises it, with any character that
 * may not appear in a header percent-encoded.
 */
export function safeRedirectPath(path?: string | null): string | undefined {
  if (!path || !path.startsWith("/") || path.startsWith("//")) {
    return undefined;
  }

  if (/[\u0000-\u001F\u007F\\]/.test(path)) {
    return undefined;
  }

  if (/^\/[a-zA-Z][a-zA-Z0-9+.-]*:/.test(path)) {
    return undefined;
  }

  let url: URL;

  try {
    url = new URL(path, ORIGIN);
  } catch {
    return undefined;
  }

  if (url.origin !== ORIGIN) {
    return undefined;
  }

  // Checked again after parsing: removing dot segments turns "/.//evil.com"
  // and "/%2e%2e//evil.com" into "//evil.com".
  const result = url.pathname + url.search + url.hash;

  return result.startsWith("//") ? undefined : result;
}
