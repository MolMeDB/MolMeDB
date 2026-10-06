"use client";

import { Accordion, AccordionItem } from "@heroui/react";

type Column = { name: string; required: string; description: string };

const commonColumns: Column[] = [
  { name: "smiles", required: "required", description: "SMILES of the compound." },
  { name: "name, pubchem, pdb, chembl, chebi, drugbank", required: "optional", description: "Compound identifiers." },
  { name: "temperature, ph, charge, logp", required: "optional", description: "Measurement conditions and compound properties (numbers)." },
  { name: "comment", required: "optional", description: "Free text, up to 255 characters." },
  { name: "primaryReference", required: "optional", description: "Publication of the measurement (DOI or PMID)." },
];

const passiveColumns: Column[] = [
  { name: "Xmin, Gpen, Gwat, LogK, LogPerm", required: "at least one", description: "Interaction values (numbers). Each can be followed by an accuracy column, e.g. Xmin_acc." },
];

const activeColumns: Column[] = [
  { name: "active_target", required: "required", description: "UniProt ID of the target protein, e.g. P00533. It is checked against UniProt." },
  { name: "protein_name", required: "optional", description: "Name of the protein, up to 255 characters. If the protein already exists, the name is added to its known names; existing names are kept." },
  { name: "interaction_type", required: "optional", description: "Category of the interaction, see the table below. Empty means Unassigned. An unknown value rejects the upload." },
  { name: "ec50, Ic50, ki, km", required: "at least one", description: "Interaction values (numbers). Each can be followed by an accuracy column, e.g. ec50_acc." },
];

const interactionTypes: [string, string][] = [
  ["Inhibitor", "The compound inhibits the target."],
  ["Non-inhibitor", "The compound was tested and does not inhibit the target."],
  ["Substrate", "The compound is transported or metabolised by the target."],
  ["Non-substrate", "The compound was tested and is not a substrate of the target."],
  ["Substrate + inhibitor", "The compound is both a substrate and an inhibitor."],
  ["Substrate + Noninhibitor", "The compound is a substrate but not an inhibitor."],
  ["Nonsubstate + inhibitor", "The compound is an inhibitor but not a substrate."],
  ["Nonsubtrate + noninhibitor", "The compound is neither a substrate nor an inhibitor."],
  ["Activator", "The compound increases the activity of the target."],
  ["Agonist", "The compound activates a receptor target."],
  ["Antagonist", "The compound blocks a receptor target."],
  ["Interacts", "An interaction was observed without a more specific classification."],
  ["N/A", "The type is not available."],
];

function ColumnTable({ columns }: { columns: Column[] }) {
  return (
    <ul className="flex flex-col gap-1 text-sm">
      {columns.map((column) => (
        <li key={column.name}>
          <code>{column.name}</code> ({column.required}): {column.description}
        </li>
      ))}
    </ul>
  );
}

export default function UploadFormatGuide({ datasetType }: { datasetType: string }) {
  const isActive = datasetType === "2";

  return (
    <Accordion variant="bordered" isCompact>
      <AccordionItem
        key="format"
        aria-label="File format"
        title={`File format for ${isActive ? "active" : "passive"} interactions`}
      >
        <div className="flex flex-col gap-3 pb-2 text-sm">
          <p>
            CSV or TSV file with a header row. The separator (comma, semicolon or tab) is detected
            automatically, and column names are matched regardless of case. Columns you do not
            name correctly can be mapped manually in the next step.
          </p>
          <ColumnTable columns={[...commonColumns, ...(isActive ? activeColumns : passiveColumns)]} />
          {isActive && (
            <div>
              <p className="font-medium">Allowed values of interaction_type</p>
              <ul className="mt-1 flex flex-col gap-1">
                {interactionTypes.map(([type, meaning]) => (
                  <li key={type}>
                    <code>{type}</code>: {meaning}
                  </li>
                ))}
              </ul>
              <p className="mt-1">Case, spaces and hyphens are ignored (&quot;non inhibitor&quot; matches &quot;Non-inhibitor&quot;).</p>
            </div>
          )}
        </div>
      </AccordionItem>
    </Accordion>
  );
}
