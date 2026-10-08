import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { FaBook } from "react-icons/fa6";
import { getJson } from "@/lib/api/admin";
import IPublication from "@/lib/api/admin/interfaces/Publication";
import { fetchPublicJsonLd } from "@/components/_core/JsonLd";
import EntityLanding from "@/components/landing/EntityLanding";
import { PUBLIC_API_URL, RDF_BASE_URL } from "@/lib/publicUrls";

async function getPublication(id: string): Promise<IPublication | null> {
  if (!/^\d+$/.test(id)) {
    return null;
  }

  return (await getJson(`/api/publication/${id}`, {}, { auth: false, revalidate: 3600 }))?.data?.data ?? null;
}

function publicationTitle(publication: IPublication): string {
  return publication.title || publication.citation || `Publication ${publication.id}`;
}

export async function generateMetadata(props: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const id = (await props.params).id;
  const publication = await getPublication(id);

  if (!publication) {
    return { title: "Publication not found | MolMeDB" };
  }

  return {
    title: `${publicationTitle(publication)} | MolMeDB`,
    description: `Molecule–membrane and molecule–transporter interaction data from this source in MolMeDB, the Molecules on Membranes Database.`,
    alternates: { canonical: `/publication/${publication.id}` },
  };
}

export default async function PublicationPage(props: { params: Promise<{ id: string }> }) {
  const id = (await props.params).id;
  const publication = await getPublication(id);

  if (!publication) {
    notFound();
  }

  const authors = (publication.authors ?? [])
    .map((author) => author.full_name || [author.first_name, author.last_name].filter(Boolean).join(" "))
    .filter(Boolean)
    .join(", ");
  const pmid = publication.identifier?.source === "MED" ? publication.identifier.value : undefined;

  return (
    <EntityLanding
      icon={<FaBook />}
      title={publicationTitle(publication)}
      subtitle="Publication"
      facts={[
        { label: "Citation", value: publication.citation },
        { label: "Authors", value: authors },
        { label: "Journal", value: publication.journal },
        { label: "Year", value: publication.year },
        { label: "DOI", value: publication.doi, href: publication.doi ? `https://doi.org/${publication.doi}` : undefined },
        { label: "PubMed", value: pmid, href: pmid ? `https://pubmed.ncbi.nlm.nih.gov/${pmid}/` : undefined },
        { label: "Passive interactions", value: publication.stats?.passive_interactions },
        { label: "Active interactions", value: publication.stats?.active_interactions },
      ]}
      apiUrl={`${PUBLIC_API_URL}/publications/${publication.id}`}
      rdfIri={`${RDF_BASE_URL}/reference/ref${publication.id}`}
      jsonLd={await fetchPublicJsonLd(`publications/${publication.id}`)}
    />
  );
}
