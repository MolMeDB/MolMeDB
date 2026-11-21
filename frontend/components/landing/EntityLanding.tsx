import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import { JsonLdScript } from "@/components/_core/JsonLd";
import { ReactNode } from "react";

export type EntityFact = {
  label: string;
  value: ReactNode;
  href?: string;
};

/**
 * Server-rendered page about one MolMeDB entity (membrane, method, protein,
 * publication): the persistent landing page its RDF IRI and JSON-LD point to.
 * Key facts and machine-readable links are rendered on the server so crawlers
 * see them; `children` can add the interactive detail.
 */
export default function EntityLanding(props: {
  icon: ReactNode;
  title: string;
  subtitle: string;
  facts: EntityFact[];
  apiUrl: string;
  rdfIri: string;
  browseHref?: string;
  jsonLd: object | null;
  children?: ReactNode;
}) {
  return (
    <>
      <JsonLdScript data={props.jsonLd} />
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <div className="text-3xl xl:text-4xl">{props.icon}</div>
            <div className="flex flex-col justify-center gap-2 lg:gap-1">
              <h1 className="text-2xl md:text-3xl font-bold">{props.title}</h1>
              <h2 className="text-lg">{props.subtitle}</h2>
            </div>
          </div>
        </div>
      </SimpleSiteHeader>
      <SiteContent>
        <div className="min-h-screen flex flex-col gap-12 pb-16">
          <dl className="grid grid-cols-1 md:grid-cols-[max-content_1fr] gap-x-8 gap-y-3 text-sm">
            {props.facts
              .filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "")
              .map((fact) => (
                <div key={fact.label} className="contents">
                  <dt className="font-semibold text-zinc-500 dark:text-zinc-400">{fact.label}</dt>
                  <dd className="break-words">
                    {fact.href ? (
                      <a href={fact.href} className="text-primary hover:underline" target="_blank" rel="noreferrer">
                        {fact.value}
                      </a>
                    ) : (
                      fact.value
                    )}
                  </dd>
                </div>
              ))}
            <dt className="font-semibold text-zinc-500 dark:text-zinc-400">Machine-readable</dt>
            <dd className="flex flex-wrap gap-x-4 gap-y-1">
              <a href={props.apiUrl} className="text-primary hover:underline">
                REST API
              </a>
              <a href={props.rdfIri} className="text-primary hover:underline">
                RDF
              </a>
              {props.browseHref && (
                <a href={props.browseHref} className="text-primary hover:underline">
                  Show in browser
                </a>
              )}
            </dd>
          </dl>
          {props.children}
        </div>
      </SiteContent>
      <SiteFooter />
    </>
  );
}
