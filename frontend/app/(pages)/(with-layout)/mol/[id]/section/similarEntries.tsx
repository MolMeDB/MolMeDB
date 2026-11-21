"use client";

import IStructure, {
  ISimilarStructure,
} from "@/lib/api/admin/interfaces/Structure";
import DetailSection from "../components/section";
import { addToast, Card, CardBody, CardHeader, Image } from "@heroui/react";
import { useEffect, useState } from "react";
import { getJson } from "@/lib/api/admin";

type ResponseType = {
  similar_structures: ISimilarStructure[];
  related_structures: ISimilarStructure[];
};

export default function CompoundSimilarEntries(props: {
  compound: IStructure;
}) {
  const [relatedEntries, setRelatedEntries] = useState<ISimilarStructure[]>([]);
  const [similarEntries, setSimilarEntries] = useState<ISimilarStructure[]>([]);

  useEffect(() => {
    getJson(
      "/api/structure/" + props.compound.identifier + "/similarities"
    ).then((res) => {
      if (res?.code === 200 && res.data) {
        const data: ResponseType = res.data;
        setRelatedEntries(data.related_structures);
        setSimilarEntries(data.similar_structures);
        return;
      }

      addToast({
        title: "Error",
        description: "Failed to load similarity data. Please, try again.",
        color: "danger",
        shouldShowTimeoutProgress: true,
        timeout: 4500,
      });
    });
  }, [props.compound.identifier]);

  return (
    <DetailSection title="Related entries" order={4}>
      <div className="flex flex-col gap-2">
        <EntriesRow
          title="Forms and parent"
          entries={relatedEntries}
          emptyText="No related entries found..."
        />
        <EntriesRow
          title="Similar entries"
          description="Tanimoto similarity of at least 0.8"
          entries={similarEntries}
          emptyText="No similar entries found..."
        />
      </div>
    </DetailSection>
  );
}

function EntriesRow(props: {
  title: string;
  description?: string;
  entries: ISimilarStructure[];
  emptyText: string;
}) {
  return (
    <div className="flex flex-col">
      <div className="flex flex-row items-baseline gap-3 px-6">
        <h3 className="text-lg font-semibold text-foreground/80">
          {props.title}
        </h3>
        {props.description && (
          <span className="text-sm text-foreground/50">
            {props.description}
          </span>
        )}
      </div>
      <div className="flex flex-row gap-4 overflow-x-scroll scroll whitespace-nowrap p-6 pb-12">
        {props.entries.length ? (
          props.entries.map((entry) => (
            <Record key={entry.identifier} compound={entry} />
          ))
        ) : (
          <div className="h-24 w-full flex flex-col justify-center items-center">
            <label className="text-xl font-bold text-foreground-400 select-none">
              {props.emptyText}
            </label>
          </div>
        )}
      </div>
    </div>
  );
}

function Record(props: { compound: ISimilarStructure }) {
  return (
    <a href={`/mol/${props.compound.identifier}`}>
      <Card className="p-4 min-w-64 max-w-64 h-[350px] cursor-pointer">
        <CardHeader className="w-full h-36 p-2 flex justify-center items-center rounded-xl dark:bg-background/70">
          <Image
            src={props.compound?.structure_2d_url}
            alt="Molecule 2D structure"
            radius="none"
            classNames={{
              wrapper: "h-full w-full flex justify-center items-center",
            }}
            className="max-h-full max-w-full object-contain"
          />
        </CardHeader>
        <CardBody className="flex flex-col">
          <div className="flex flex-row justify-center">
            <h3 className="text-lg font-semibold text-foreground/80 line-clamp-1">
              {props.compound?.name ?? props.compound.identifier}
            </h3>
          </div>
          <div className="flex flex-col gap-1 mt-4 text-sm text-foreground/70">
            {props.compound.similarity && (
              <div className="flex flex-row items-center border-b-1 border-foreground/30">
                <div className="w-1/2">Similarity</div>
                <div className="w-1/2 text-right">
                  {props.compound.similarity.tanimoto}
                </div>
              </div>
            )}
            <div className="flex flex-row items-center border-b-1 border-foreground/30">
              <div className="w-1/2">Molecular weight</div>
              <div className="w-1/2 text-right">
                {props.compound.molecular_weight ?? "N/A"}
              </div>
            </div>
            <div className="flex flex-row items-center border-b-1 border-foreground/30">
              <div className="w-1/2">LogP</div>
              <div className="w-1/2 text-right">
                {props.compound.logp ?? "N/A"}
              </div>
            </div>
            <div className="flex flex-row items-center border-b-1 border-foreground/30">
              <div className="w-1/2"># passive int.</div>
              <div className="w-1/2 text-right">
                {props.compound.total?.interactions_passive ?? "N/A"}
              </div>
            </div>
            <div className="flex flex-row items-center border-b-1 border-foreground/30">
              <div className="w-1/2"># active int.</div>
              <div className="w-1/2 text-right">
                {props.compound.total?.interactions_active ?? "N/A"}
              </div>
            </div>
          </div>
        </CardBody>
      </Card>
    </a>
  );
}
