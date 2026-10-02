import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { FaLayerGroup } from "react-icons/fa6";
import { getJson } from "@/lib/api/admin";
import { IMembraneStats } from "@/lib/api/admin/interfaces/Membrane";
import { fetchPublicJsonLd } from "@/components/_core/JsonLd";
import EntityLanding from "@/components/landing/EntityLanding";
import ClientOnly from "@/components/_core/ClientOnly";
import SectionDetail from "../../browse/membranes/section/Detail";
import { PUBLIC_API_URL, RDF_BASE_URL } from "@/lib/publicUrls";

async function getMembraneStats(id: string): Promise<IMembraneStats | null> {
  if (!/^\d+$/.test(id)) {
    return null;
  }

  return (await getJson(`/api/membrane/${id}/stats`, {}, { auth: false, revalidate: 3600 }))?.data?.data ?? null;
}

export async function generateMetadata(props: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const id = (await props.params).id;
  const stats = await getMembraneStats(id);

  if (!stats) {
    return { title: "Membrane not found | MolMeDB" };
  }

  return {
    title: `${stats.membrane.name} | Membrane | MolMeDB`,
    description: `Interactions of molecules with the ${stats.membrane.name} membrane model in MolMeDB, the Molecules on Membranes Database.`,
    alternates: { canonical: `/membrane/${stats.membrane.id}` },
  };
}

export default async function MembranePage(props: { params: Promise<{ id: string }> }) {
  const id = (await props.params).id;
  const stats = await getMembraneStats(id);

  if (!stats) {
    notFound();
  }

  const membrane = stats.membrane;

  return (
    <EntityLanding
      icon={<FaLayerGroup />}
      title={membrane.name}
      subtitle="Membrane"
      facts={[
        { label: "Abbreviation", value: membrane.abbreviation },
        { label: "Passive interactions", value: stats.total.interactions_passive },
        { label: "Molecules", value: stats.total.structures },
      ]}
      apiUrl={`${PUBLIC_API_URL}/membranes/${membrane.id}`}
      rdfIri={`${RDF_BASE_URL}/interaction/membrane${membrane.id}`}
      browseHref={`/browse/membranes?id=${membrane.id}`}
      jsonLd={await fetchPublicJsonLd(`membranes/${membrane.id}`)}
    >
      <ClientOnly>
        <SectionDetail membraneId={String(membrane.id)} scrollIntoView={false} />
      </ClientOnly>
    </EntityLanding>
  );
}
