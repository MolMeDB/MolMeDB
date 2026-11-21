"use client";

import { Accordion, AccordionItem } from "@heroui/react";
import { FiInfo } from "react-icons/fi";

type Column = { names: string[]; required: string; description: string };

const commonColumns: Column[] = [
  { names: ["SMILES"], required: "required", description: "Structure of the compound." },
  {
    names: ["Name", "Pubchem ID", "RCSB ligand ID", "ChEMBL ID", "ChEBI ID", "Drugbank ID"],
    required: "optional",
    description: "Compound identifiers.",
  },
  { names: ["Temperature [C]"], required: "optional", description: "Number, 0 or higher." },
  { names: ["pH"], required: "optional", description: "Number between 0 and 14." },
  { names: ["Charge [Q]"], required: "optional", description: "Integer between -20 and 20." },
  { names: ["LogP"], required: "optional", description: "Number." },
  { names: ["Comment"], required: "optional", description: "Free text, up to 255 characters." },
  {
    names: ["Primary ref."],
    required: "optional",
    description: "DOI or PubMed ID of the measurement. When empty, the Secondary reference from this form is used.",
  },
];

const passiveColumns: Column[] = [
  {
    names: ["Xmin", "Gpen", "Gwat", "LogK", "LogPerm"],
    required: "at least one",
    description: "Interaction values (numbers). Each has an optional accuracy column, e.g. +/- Xmin.",
  },
];

const activeColumns: Column[] = [
  {
    names: ["Target (Uniprot ID)"],
    required: "required",
    description: "UniProt ID of the target protein, e.g. P00533. It is checked against UniProt.",
  },
  {
    names: ["Protein name"],
    required: "optional",
    description:
      "Name of the target protein, up to 255 characters. A name the protein does not have yet is added to its names; existing names are kept.",
  },
  {
    names: ["Interaction type"],
    required: "optional",
    description: "One of the values listed below. An empty cell means Unassigned; an unknown value is reported as an error.",
  },
  {
    names: ["Ec50", "Ic50", "Ki", "Km"],
    required: "at least one",
    description: "Interaction values (numbers). Each has an optional accuracy column, e.g. +/- Ec50.",
  },
];

const interactionTypes: [string, string][] = [
  ["Inhibitor", "The compound inhibits the target."],
  ["Non-inhibitor", "The compound was tested and does not inhibit the target."],
  ["Substrate", "The compound is transported or metabolised by the target."],
  ["Non-substrate", "The compound was tested and is not a substrate of the target."],
  ["Substrate + inhibitor", "The compound is both a substrate and an inhibitor."],
  ["Substrate + Noninhibitor", "The compound is a substrate but not an inhibitor."],
  ["Non-substrate + inhibitor", "The compound is an inhibitor but not a substrate."],
  ["Non-substrate + non-inhibitor", "The compound is neither a substrate nor an inhibitor."],
  ["Activator", "The compound increases the activity of the target."],
  ["Agonist", "The compound activates a receptor target."],
  ["Antagonist", "The compound blocks a receptor target."],
  ["Interacts", "An interaction was observed without a more specific classification."],
  ["N/A", "The type is not available."],
];

function ColumnList({ columns }: { columns: Column[] }) {
  return (
    <ul className="flex flex-col gap-1">
      {columns.map((column) => (
        <li key={column.names.join()}>
          <span className="font-medium">{column.names.join(", ")}</span>{" "}
          <span className="text-default-500">({column.required})</span>: {column.description}
        </li>
      ))}
    </ul>
  );
}

export default function UploadFormatGuide({ datasetType }: { datasetType: string }) {
  const isActive = datasetType === "2";

  return (
    <div className="rounded-xl border border-primary-200 bg-primary-50/70 dark:border-primary-500/40 dark:bg-primary-950/20">
      <Accordion variant="light" isCompact>
        <AccordionItem
          key="format"
          aria-label="File format"
          startContent={<FiInfo className="text-primary" />}
          title={`File format for ${isActive ? "active" : "passive"} interactions`}
        >
          <div className="flex flex-col gap-3 pb-2 text-sm">
            <p>
              Upload a CSV file. In the next step you choose the separator (comma, semicolon or tab),
              whether the first row is a header, and assign each column of the file to one of the
              column types below. Columns you do not need can be ignored.
            </p>
            {!isActive && (
              <p>Membrane and method are selected in this form and apply to all rows.</p>
            )}
            <ColumnList columns={[...commonColumns, ...(isActive ? activeColumns : passiveColumns)]} />
            {isActive && (
              <div>
                <p className="font-medium">Allowed interaction types</p>
                <ul className="mt-1 flex flex-col gap-1">
                  {interactionTypes.map(([type, meaning]) => (
                    <li key={type}>
                      <code>{type}</code>: {meaning}
                    </li>
                  ))}
                </ul>
                <p className="mt-1 text-default-500">
                  Case, spaces and hyphens are ignored, so &quot;non inhibitor&quot; matches &quot;Non-inhibitor&quot;.
                </p>
              </div>
            )}
          </div>
        </AccordionItem>
      </Accordion>
    </div>
  );
}
