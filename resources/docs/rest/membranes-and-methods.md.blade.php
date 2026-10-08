---
title: Membranes and methods
position: 3
---
Passive interactions are measured on a **membrane** (a lipid bilayer, a cell layer or a tissue model, such as DOPC or Caco-2) by a **method** (an experimental assay, such as PAMPA, or a calculation, such as COSMOmic). Both are organized in categories, which the `/categories` endpoints return as trees with the membranes or methods of every category.

The interactions of a membrane or method are available in two forms:

- `/interactions`: a paginated JSON list with the filters of [`/interactions/passive`](/docs/rest/interactions);
- `/interactions/export`: all of them at once as a ZIP archive with a CSV file and the license, refreshed daily.

## Membranes

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('membranes*')])
## Methods

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('methods*')])
