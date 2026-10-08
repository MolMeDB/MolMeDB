---
title: Uploading your data
---
@php
    $file = $docs->uploadFile();
    $passive = $docs->uploadColumns(\App\Models\UploadQueue::TYPE_PASSIVE_DATASET);
    $active = $docs->uploadColumns(\App\Models\UploadQueue::TYPE_ACTIVE_DATASET);
    $common = array_intersect_key($passive, $active);
    $states = $docs->uploadStates();
    $columns = [
        'smiles' => 'Structure of the molecule. **Required.**',
        'name' => 'Name of the molecule.',
        'pubchem' => 'PubChem CID.',
        'pdb' => 'PDB ligand (chemical component) code.',
        'chembl' => 'ChEMBL id.',
        'chebi' => 'ChEBI id.',
        'drugbank' => 'DrugBank id.',
        'temperature' => 'Temperature of the measurement in °C.',
        'charge' => 'Charge of the measured form.',
        'ph' => 'pH of the measurement.',
        'comment' => 'Note shown with the interaction.',
        'primaryReference' => 'Publication of this row (DOI or PubMed ID), when it differs from the secondary reference of the dataset.',
        'logp' => 'LogP of the molecule.',
        'x_min' => 'Xmin, position of the energy minimum in the membrane [nm].',
        'g_pen' => 'ΔGpen, penetration barrier [kcal/mol].',
        'g_wat' => 'ΔGwat, affinity towards the membrane [kcal/mol].',
        'logk' => 'LogK, membrane-water partition coefficient [log10 mol_m/mol_w].',
        'logperm' => 'LogPerm, permeability coefficient [log10 cm/s].',
        'active_target' => 'UniProt id of the transporter. **Required.**',
        'protein_name' => 'Name of the transporter.',
        'interaction_type' => 'Type of the interaction: '.implode(', ', $docs->activeInteractionTypes()).'.',
        'km' => 'pKm, negative decimal logarithm of the Michaelis constant [M].',
        'ec50' => 'pEC50, negative decimal logarithm of the half maximal effective concentration [M].',
        'ki' => 'pKi, negative decimal logarithm of the inhibition constant [M].',
        'Ic50' => 'pIC50, negative decimal logarithm of the half maximal inhibitory concentration [M].',
    ];
    $describe = fn (string $key): string => $columns[$key] ?? (str_ends_with($key, '_acc') ? 'Accuracy (error) of the value in the previous column.' : '');
    $meanings = [
        \App\Models\UploadQueue::STATE_UPLOADED => 'The file is uploaded and waits for its column mapping (step 4).',
        \App\Models\UploadQueue::STATE_CONFIGURED => 'The mapping is valid and the upload waits to be started (step 6).',
        \App\Models\UploadQueue::STATE_PENDING => 'The upload waits in the queue to be processed.',
        \App\Models\UploadQueue::STATE_RUNNING => 'Every row is being checked in detail, or, after the approval, imported. A progress bar shows how far it got.',
        \App\Models\UploadQueue::STATE_REVIEW_REQUIRED => 'The automatic checks passed and the MolMeDB team reviews the data.',
        \App\Models\UploadQueue::STATE_DONE => 'The data are imported and public in MolMeDB.',
        \App\Models\UploadQueue::STATE_ERROR => 'The checks found problems; see the logs of the upload.',
        \App\Models\UploadQueue::STATE_CANCELED => 'The upload was canceled.',
    ];
@endphp
**Where to find it?** The upload form is at [Laboratory → Upload dataset](/lab/upload).

This guide walks through uploading your own dataset of passive or active interactions, from the form to its publication. Every dataset is reviewed by the MolMeDB team before it is published; please allow up to 5 business days for the review. You are notified by e-mail about every change of its state.

## Step 1: Sign in

You need to be signed in to upload data. Both ways lead to the same account:

- **E-mail code**, right on the upload page and without registration: enter your e-mail, complete the captcha, click *Send verification code* and enter the 6-digit code from the e-mail.
- **MolMeDB account**: log in with the *log in* link.

Your uploads are listed under *My uploads* whichever way you sign in. You can also open the state of an upload with the tracking link from the e-mail, without signing in.

## Step 2: Describe the dataset

- **Dataset type**: *Passive interactions* (a molecule and a membrane, measured by a method) or *Active interactions* (a molecule and a transporter protein). See [What is stored in MolMeDB?](/docs/about/about-data) for the difference.
- **Dataset name** (optional): generated when left empty.
- **Comment** (optional): notes for the reviewers.
- **Membrane** and **method** (passive interactions only, required): search them by name or abbreviation. The form offers only membranes and methods already in MolMeDB. If yours is missing, write to [{{ $docs->fair('contact_email') }}](mailto:{{ $docs->fair('contact_email') }}) or use the feedback button, and we will add it.
- **Secondary reference** (required): the publication of the whole dataset. Search it by PubMed ID, title or citation (at least 3 characters) in Europe PMC and MolMeDB, and pick one of the results.

> [!NOTE]
> The **secondary reference** belongs to the whole dataset. A **primary reference** can be given for each row in the file (the *Primary ref.* column) when a value was first reported in another publication; rows without it get the secondary reference.

## Step 3: Attach the file and submit

Upload a {{ strtoupper(implode(', ', $file['extensions'])) }} file of at most {{ intdiv($file['max_kilobytes'], 1024) }} MB with one interaction per row, complete the captcha and click *Submit upload request*. The upload appears in *My uploads* as *{{ $states[\App\Models\UploadQueue::STATE_UPLOADED] }}*.

## Step 4: Map the columns

Click *Configure* on the upload. The dialog shows the first rows of your file:

- **Delimiter**: comma, semicolon or tab.
- **Skip first row**: when the first row is a header.
- For every column, pick what it contains, or *Ignore* to leave it out.

Columns of both dataset types:

| Column | Content |
|---|---|
@foreach ($common as $key => $label)
| {{ $label }} | {{ $docs->cell($describe($key)) }} |
@endforeach

Passive interactions add the measured values. At least one of them is required; each can have its accuracy in the column next to it:

| Column | Content |
|---|---|
@foreach (array_diff_key($passive, $common) as $key => $label)
| {{ $label }} | {{ $docs->cell($describe($key)) }} |
@endforeach

Active interactions add the transporter and the values. The transporter and at least one value are required:

| Column | Content |
|---|---|
@foreach (array_diff_key($active, $common) as $key => $label)
| {{ $label }} | {{ $docs->cell($describe($key)) }} |
@endforeach

## Step 5: Validate the mapping

Click *Validate*. The whole file is checked with your mapping; problems such as a badly formatted value or a missing required column are listed. Fix the mapping or the file and validate again. When the check passes, *Start upload* becomes active.

## Step 6: Start the upload

Click *Start upload* and confirm. The upload moves to the processing queue; the mapping can no longer be changed unless you revert it (see below).

## Step 7: Follow its progress

*My uploads* refreshes every 15 seconds. An upload goes through these states:

| State | Meaning |
|---|---|
@foreach ($meanings as $state => $meaning)
| {{ $states[$state] }} | {{ $meaning }} |
@endforeach

*Logs* on an upload shows its whole history: checks, changes of the state and notes of the reviewers.

## When something goes wrong

An upload in the *{{ $states[\App\Models\UploadQueue::STATE_ERROR] }}* state can take a corrected file: choose it and click *Reupload*. The upload returns to *{{ $states[\App\Models\UploadQueue::STATE_UPLOADED] }}*; continue from step 4.

Other actions on an upload:

- **Download file**: the file you uploaded.
- **Revert**: returns a waiting upload to the column mapping, for example to change it.
- **Cancel**: cancels the upload and deletes its file. This cannot be undone.
