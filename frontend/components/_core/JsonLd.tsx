/**
 * Fetches the schema.org/Bioschemas JSON-LD representation of a public API
 * resource (path relative to /api/v1) on the server, so the PHP mappers
 * (App\Support\JsonLd\*) stay the single source of truth for both the API and
 * the HTML pages.
 */
export async function fetchPublicJsonLd(path: string, revalidate = 3600): Promise<object | null> {
  try {
    const res = await fetch(`${process.env.NEXT_BACKEND_URL}/api/v1/${path.replace(/^\/+/, "")}`, {
      headers: { Accept: "application/ld+json" },
      next: { revalidate },
    });

    if (!res.ok) {
      return null;
    }

    return await res.json();
  } catch {
    return null;
  }
}

export function JsonLdScript(props: { data: object | null }) {
  if (!props.data) {
    return null;
  }

  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: JSON.stringify(props.data).replace(/</g, "\\u003c") }}
    />
  );
}
