import { IUser } from "./User";

export default interface IStructure {
  id: number;
  parent_identifier?: string;
  identifier: string;
  identifiers: IIdentifier[];
  name?: string;
  canonical_smiles: string;
  charge?: string;
  inchi?: string;
  inchikey?: string;
  molecular_weight?: number;
  logp?: number;
  structure_3d_url?: string;
  structure_2d_url?: string;
  structure_2d_url_big?: string;
}

export enum IIdentifierType {
  NAME = 1,
  PUBCHEM = 4,
  DRUGBANK = 5,
  CHEBI = 6,
  PDB = 7,
  CHEMBL = 8,
}

export enum IIdentifierState {
  NEW = 1,
  VALIDATED = 2,
  INVALID = 3,
}

export interface IIdentifier {
  id: number;
  value: string;
  type: IIdentifierType;
  enum_type: string;
  state: number;
  enum_state: string;
  source?: IIdentifierSource; // Max depth = 1
}

export type IIdentifierSource =
  | { type: "user"; data: IUser }
  | { type: "identifier"; data: IIdentifier };

export interface ISimilarStructure extends IStructure {
  // Only for similar (not related) structures.
  similarity: {
    tanimoto: number;
  } | null;
  total: {
    interactions_passive: number;
    interactions_active: number;
  };
}
