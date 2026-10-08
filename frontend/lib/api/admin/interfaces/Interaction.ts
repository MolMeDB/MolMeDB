import { IDatasetActive, IDatasetPassive } from "./Dataset";
import IProtein from "./Protein";
import IPublication from "./Publication";

export interface IInteractionPassive {
  id: number;
  dataset: IDatasetPassive;
  structure_id: number;
  temperature?: number;
  ph?: number;
  charge?: string;
  note?: string;
  measurements: {
    x_min?: IApproximateValue;
    gpen?: IApproximateValue;
    gwat?: IApproximateValue;
    logk?: IApproximateValue;
    logperm?: IApproximateValue;
  };
  primary_reference?: IPublication;
  secondary_reference?: IPublication;
  type: "passive";
}

export interface IInteractionActive {
  id: number;
  dataset: IDatasetActive;
  structure_id: number;
  protein: IProtein;
  /** Substrate, Non-substrate, Inhibitor or Non-inhibitor. */
  interaction_type?: string | null;
  note?: string;
  temperature?: number;
  ph?: number;
  charge?: string;
  measurements: {
    km?: IApproximateValue;
    ki?: IApproximateValue;
    ec50?: IApproximateValue;
    ic50?: IApproximateValue;
  };
  primary_reference?: IPublication;
  secondary_reference?: IPublication;
  type: "active";
}

export interface IApproximateValue {
  value: number;
  accuracy?: number;
}
