import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { FaDna } from "react-icons/fa6";
import { getJson } from "@/lib/api/admin";
import { IProteinStats } from "@/lib/api/admin/interfaces/Protein";
import { fetchPublicJsonLd } from "@/components/_core/JsonLd";
import EntityLanding from "@/components/landing/EntityLanding";
import SectionDetail from "../../browse/proteins/section/Detail";
import { PUBLIC_API_URL, RDF_BASE_URL } from "@/lib/publicUrls";

async function getProteinStats(id: string): Promise<IProteinStats | null> {
  if (!/^\d+$/.test(id)) {
    return null;
  }

  return (await getJson(`/api/protein/${id}/stats`, {}, { auth: false, revalidate: 3600 }))?.data?.data ?? null;
}

function proteinName(stats: IProteinStats): string {
  return stats.protein.identifiers?.find((identifier) => identifier.type === "Name")?.value ?? stats.protein.uniprot_id;
}

export async function generateMetadata(props: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const id = (await props.params).id;
  const stats = await getProteinStats(id);

  if (!stats) {
    return { title: "Protein not found | MolMeDB" };
  }

  const name = proteinName(stats);

  return {
    title: `${name} | Transporter | MolMeDB`,
    description: `Interactions of molecules with the ${name} transporter (UniProt ${stats.protein.uniprot_id}) in MolMeDB, the Molecules on Membranes Database.`,
    alternates: { canonical: `/protein/${stats.protein.id}` },
  };
}

export default async function ProteinPage(props: { params: Promise<{ id: string }> }) {
  const id = (await props.params).id;
  const stats = await getProteinStats(id);

  if (!stats) {
    notFound();
  }

  const protein = stats.protein;

  return (
    <EntityLanding
      icon={<FaDna />}
      title={proteinName(stats)}
      subtitle="Transporter protein"
      facts={[
        { label: "UniProt", value: protein.uniprot_id, href: `https://www.uniprot.org/uniprotkb/${protein.uniprot_id}` },
        { label: "Active interactions", value: stats.interactions_count },
        { label: "Molecules", value: stats.structures_count },
      ]}
      apiUrl={`${PUBLIC_API_URL}/proteins/${protein.id}`}
      rdfIri={`${RDF_BASE_URL}/transporter/target${protein.id}`}
      browseHref={`/browse/proteins?id=${protein.id}`}
      jsonLd={await fetchPublicJsonLd(`proteins/${protein.id}`)}
    >
      <SectionDetail proteinId={String(protein.id)} />
    </EntityLanding>
  );
}
