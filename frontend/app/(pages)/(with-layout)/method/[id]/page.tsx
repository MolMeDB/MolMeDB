import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { FaFlask } from "react-icons/fa6";
import { getJson } from "@/lib/api/admin";
import { IMethodStats } from "@/lib/api/admin/interfaces/Method";
import { fetchPublicJsonLd } from "@/components/_core/JsonLd";
import EntityLanding from "@/components/landing/EntityLanding";
import ClientOnly from "@/components/_core/ClientOnly";
import SectionDetail from "../../browse/methods/section/Detail";
import { PUBLIC_API_URL, RDF_BASE_URL } from "@/lib/publicUrls";

async function getMethodStats(id: string): Promise<IMethodStats | null> {
  if (!/^\d+$/.test(id)) {
    return null;
  }

  return (await getJson(`/api/method/${id}/stats`, {}, { auth: false, revalidate: 3600 }))?.data?.data ?? null;
}

export async function generateMetadata(props: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const id = (await props.params).id;
  const stats = await getMethodStats(id);

  if (!stats) {
    return { title: "Method not found | MolMeDB" };
  }

  return {
    title: `${stats.method.name} | Method | MolMeDB`,
    description: `Interactions of molecules measured by the ${stats.method.name} method in MolMeDB, the Molecules on Membranes Database.`,
    alternates: { canonical: `/method/${stats.method.id}` },
  };
}

export default async function MethodPage(props: { params: Promise<{ id: string }> }) {
  const id = (await props.params).id;
  const stats = await getMethodStats(id);

  if (!stats) {
    notFound();
  }

  const method = stats.method;

  return (
    <EntityLanding
      icon={<FaFlask />}
      title={method.name}
      subtitle="Method"
      facts={[
        { label: "Abbreviation", value: method.abbreviation },
        { label: "Passive interactions", value: stats.total.interactions_passive },
        { label: "Molecules", value: stats.total.structures },
      ]}
      apiUrl={`${PUBLIC_API_URL}/methods/${method.id}`}
      rdfIri={`${RDF_BASE_URL}/interaction/method${method.id}`}
      browseHref={`/browse/methods?id=${method.id}`}
      jsonLd={await fetchPublicJsonLd(`methods/${method.id}`)}
    >
      <ClientOnly>
        <SectionDetail methodId={String(method.id)} scrollIntoView={false} />
      </ClientOnly>
    </EntityLanding>
  );
}
