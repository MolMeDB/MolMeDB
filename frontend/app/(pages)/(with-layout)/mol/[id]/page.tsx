import type { Metadata } from "next";
import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import DownloaderSuggestion from "@/components/downloader/DownloaderSuggestion";
import { SiMoleculer } from "react-icons/si";
import CompoundBasicProperties from "./section/basicProperties";
import Compound2D3DStructure from "./section/structure";
import CompoundIdentifiers from "./section/identifiers";
import CompoundSimilarEntries from "./section/similarEntries";
import CompoundActiveInteractions from "./section/interactionActive";
import CompoundPassiveInteractions from "./section/interactionPassive";
import IStructure from "@/lib/api/admin/interfaces/Structure";
import { getJson } from "@/lib/api/admin";
import { fetchPublicJsonLd, JsonLdScript } from "@/components/_core/JsonLd";

export async function generateMetadata(props: {
  params: Promise<{ id: string }>;
}): Promise<Metadata> {
  const id = (await props.params).id;
  const compound: IStructure = (await getJson(`/api/structure/${id}`, {}, { auth: false, revalidate: 3600 }))?.data
    ?.data;

  if (!compound) {
    return { title: `Compound not found | MolMeDB` };
  }

  const name = compound.name ?? compound.identifier;

  return {
    title: `${name} | MolMeDB`,
    description: `Membrane interaction data for ${name} (${compound.identifier}) in MolMeDB, the Molecules on Membranes Database.`,
    alternates: { canonical: `/mol/${compound.identifier}` },
  };
}

export default async function CompoundDetailPage(props: {
  params: Promise<{ id: string }>;
}) {
  const id = (await props.params).id;
  const compound: IStructure = (await getJson(`/api/structure/${id}`, {}, { auth: false, revalidate: 3600 }))?.data
    ?.data;
  const jsonLd = compound ? await fetchPublicJsonLd(`structures/${compound.identifier}`) : null;

  if (!compound) {
    return (
      <>
        <SimpleSiteHeader>
          <div className="h-full w-full flex flex-col justify-end">
            <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
              <SiMoleculer className="text-3xl xl:text-4xl" />
              <div className="flex flex-col justify-center gap-2 lg:gap-1">
                <h1 className="text-2xl md:text-3xl font-bold">Not found</h1>
                <div className="flex flex-row gap-4 items-center">
                  Cannot find compound with id {id}
                </div>
              </div>
            </div>
          </div>
        </SimpleSiteHeader>
        <SiteContent>
          <div className="min-h-screen flex flex-col gap-16 pb-16"></div>
        </SiteContent>
        <SiteFooter />
      </>
    );
  }

  return (
    <>
      <JsonLdScript data={jsonLd} />
      <DownloaderSuggestion
        category="molecule"
        id={compound.identifier}
        label={compound.name ?? compound.identifier}
      />
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <SiMoleculer className="text-3xl xl:text-4xl" />
            <div className="flex flex-col justify-center gap-2 lg:gap-1">
              <h1 className="text-2xl md:text-3xl font-bold">
                {compound?.name ?? id}
              </h1>
              <div className="flex flex-row gap-4 items-center">
                {compound.identifier && <h2 className="text-lg">{id}</h2>}
                {compound.parent_identifier && (
                  <a
                    href={`/mol/${compound.parent_identifier}`}
                    className="text-sm px-3 py-1 rounded-full bg-gray-200 text-gray-800 hover:bg-gray-300 transition-colors duration-150"
                  >
                    Parent: {compound.parent_identifier}
                  </a>
                )}
              </div>
            </div>
          </div>
        </div>
      </SimpleSiteHeader>
      <SiteContent>
        <div className="min-h-screen flex flex-col gap-16 pb-16">
          <CompoundBasicProperties compound={compound} />
          <Compound2D3DStructure compound={compound} />
          <CompoundIdentifiers compound={compound} />
          <CompoundSimilarEntries compound={compound} />
          <CompoundPassiveInteractions compound={compound} />
          <CompoundActiveInteractions compound={compound} />
        </div>
      </SiteContent>
      <SiteFooter />
    </>
  );
}
