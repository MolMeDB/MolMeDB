import IUiTableColumn from "@/components/ui/table/interface/columns";
import {
  IInteractionActive,
  IInteractionPassive,
} from "@/lib/api/admin/interfaces/Interaction";

type RenderMeasurementParam<
  T extends IInteractionPassive | IInteractionActive
> = {
  data: T;
  measurement: keyof T["measurements"];
};

function renderMeasurement<T extends IInteractionPassive | IInteractionActive>({
  data,
  measurement,
}: RenderMeasurementParam<T>) {
  if (data.type == "passive") {
    const m = measurement as keyof IInteractionPassive["measurements"];
    return (
      <div className="flex flex-col items-start justify-center max-w-16">
        <div>{data.measurements[m]?.value}</div>
        {data.measurements[m]?.accuracy ? (
          <div className="flex justify-end w-full text-[10px] text-red-500">
            +/-&nbsp;{data.measurements[m].accuracy}
          </div>
        ) : null}
      </div>
    );
  }
  const m = measurement as keyof IInteractionActive["measurements"];
  return (
    <div className="flex flex-col items-start justify-center max-w-16">
      <div>{data.measurements[m]?.value}</div>
      {data.measurements[m]?.accuracy ? (
        <div className="flex justify-end w-full text-[10px] text-red-500">
          +/-&nbsp;{data.measurements[m].accuracy}
        </div>
      ) : null}
    </div>
  );
}

export const activeInteractionsColumns: IUiTableColumn<IInteractionActive>[] = [
  {
    key: "protein.uniprot_id",
    title: "Protein",
    render: (item) => item.protein.uniprot_id,
    // sortKey: "protein",
    // isSortable: true,
    isHideable: false,
  },
  {
    key: "interaction_type",
    title: "Type",
    render: (item) => item.interaction_type,
    isSortable: false,
    isHideable: true,
  },
  {
    key: "charge",
    title: "Charge",
    render: (item) => item.charge,
    isSortable: true,
    sortKey: "charge",
  },
  {
    key: "temperature",
    title: "T [°C]",
    render: (item) => item.temperature,
    isSortable: true,
    sortKey: "temperature",
  },
  {
    key: "ph",
    title: "pH",
    render: (item) => item.ph,
    isSortable: true,
    sortKey: "ph",
    isHideable: true,
  },
  {
    key: "note",
    title: "Note",
    render: (item) => item.note,
    isSortable: false,
    isHideable: true,
  },
  {
    key: "measurements.km.value",
    isHideable: true,
    title: <div className="flex flex-row">pKm</div>,
    render: (item) => renderMeasurement({ data: item, measurement: "km" }),
    isSortable: true,
    sortKey: "km",
  },
  {
    key: "measurements.ec50.value",
    isHideable: true,
    title: (
      <div className="">
        pEC<sub>50</sub>
      </div>
    ),
    render: (item) => renderMeasurement({ data: item, measurement: "ec50" }),
    isSortable: true,
    sortKey: "ec50",
  },
  {
    key: "measurements.ki.value",
    isHideable: true,
    title: <div className="flex flex-row">pKi</div>,
    render: (item) => renderMeasurement({ data: item, measurement: "ki" }),
    isSortable: true,
    sortKey: "ki",
  },
  {
    key: "measurements.ic50.value",
    isHideable: true,
    title: (
      <div className="">
        pIC<sub>50</sub>
      </div>
    ),
    render: (item) => renderMeasurement({ data: item, measurement: "ic50" }),
    isSortable: true,
    sortKey: "ic50",
  },

  {
    key: "primary_reference.citation",
    title: "Primary reference",
    render: (item) => (
      <div className="line-clamp-2 max-w-32">
        {item.primary_reference?.citation}
      </div>
    ),
    isSortable: false,
  },
  {
    key: "secondary_reference.citation",
    title: "Secondary reference",
    render: (item) => (
      <div className="line-clamp-2 max-w-32">
        {item.secondary_reference?.citation}
      </div>
    ),
    isSortable: false,
  },
];

export const passiveInteractionsColumns: IUiTableColumn<IInteractionPassive>[] =
  [
    {
      key: "membrane",
      title: "Membrane",
      render: (item) => item.dataset.membrane.abbreviation,
      sortKey: "membrane",
      isSortable: true,
      isHideable: false,
    },
    {
      key: "method",
      title: "Method",
      render: (item) => item.dataset.method.abbreviation,
      isSortable: true,
      sortKey: "method",
    },
    {
      key: "charge",
      title: "Charge",
      render: (item) => item.charge,
      isSortable: true,
      sortKey: "charge",
    },
    {
      key: "temperature",
      title: "T [°C]",
      render: (item) => item.temperature,
      isSortable: true,
      sortKey: "temperature",
    },
    {
      key: "ph",
      title: "pH",
      render: (item) => item.ph,
      isSortable: true,
      sortKey: "ph",
      isHideable: true,
    },
    {
      key: "measurements.x_min.value",
      title: (
        <p>
          X<sub>min</sub>
          <br /> [nm]
        </p>
      ),
      render: (item) => renderMeasurement({ data: item, measurement: "x_min" }),
      isHideable: true,
      sortKey: "x_min",
      isSortable: true,
    },
    {
      key: "measurements.gpen.value",
      title: (
        <p>
          G<sub>pen</sub> <br />
          [kcal/mol]
        </p>
      ),
      render: (item) => renderMeasurement({ data: item, measurement: "gpen" }),
      isSortable: true,
      sortKey: "gpen",
      isHideable: true,
    },
    {
      key: "measurements.gwat.value",
      isHideable: true,
      title: (
        <p>
          G<sub>wat</sub> <br />
          [kcal/mol]
        </p>
      ),
      render: (item) => renderMeasurement({ data: item, measurement: "gwat" }),
      sortKey: "gwat",
      isSortable: true,
    },
    {
      key: "measurements.logk.value",
      isHideable: true,
      title: (
        <p>
          LogK<sub>m</sub> <br />
          [mol<sub>m</sub>/mol<sub>w</sub>]
        </p>
      ),
      render: (item) => renderMeasurement({ data: item, measurement: "logk" }),
      isSortable: true,
      sortKey: "logk",
    },
    {
      key: "measurements.logperm.value",
      isHideable: true,
      title: (
        <div className="flex flex-row">
          LogPerm <br />
          [cm/s]
        </div>
      ),
      render: (item) =>
        renderMeasurement({ data: item, measurement: "logperm" }),
      isSortable: true,
      sortKey: "logperm",
    },
    {
      key: "primary_reference.citation",
      title: "Primary reference",
      render: (item) => (
        <div className="line-clamp-2 max-w-32">
          {item.primary_reference?.citation}
        </div>
      ),
      isSortable: false,
    },
    {
      key: "secondary_reference.citation",
      title: "Secondary reference",
      render: (item) => (
        <div className="line-clamp-2 max-w-32">
          {item.secondary_reference?.citation}
        </div>
      ),
      isSortable: false,
    },
  ];
