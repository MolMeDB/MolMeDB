const RDF_TYPES = ["text/turtle", "application/x-turtle", "application/n-triples", "application/rdf+xml"];
const RDF_FORMATS = ["turtle", "ntriples", "rdfxml"];

/**
 * Whether a request for a page asks for its RDF description instead: an RDF
 * media type in Accept preferred over HTML (q values), or ?format=turtle |
 * ntriples | rdfxml. Browsers always prefer HTML, so they keep the page.
 */
export function wantsRdf(accept: string | null, format: string | null): boolean {
  if (format && RDF_FORMATS.includes(format)) {
    return true;
  }

  let rdf = 0;
  let html = 0;

  for (const part of (accept ?? "").split(",")) {
    const [type, ...params] = part.trim().toLowerCase().split(";");
    const qParam = params.map((param) => param.trim()).find((param) => param.startsWith("q="));
    const q = qParam ? Number(qParam.slice(2)) : 1;

    if (RDF_TYPES.includes(type)) {
      rdf = Math.max(rdf, q);
    } else if (type === "text/html" || type === "application/xhtml+xml") {
      html = Math.max(html, q);
    }
  }

  return rdf > 0 && rdf > html;
}
