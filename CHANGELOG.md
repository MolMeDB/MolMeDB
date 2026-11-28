# Changelog

Every release of MolMeDB and what it changes. A release adds its section
here before it is tagged: the Release workflow refuses a tag whose version
has no section, and puts the section at the top of the GitHub release.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- A deployment no longer waits five minutes for the old RDKit container:
  it runs with an init process, which passes SIGTERM on to the service, so
  it stops at once instead of being killed after the stop timeout.
  The deployment that brings this still waits once: the container it
  replaces was created without the init process.
- The recorded failures in the daily system health report show the last
  failure of each kind as it was recorded, with its numbers, its time and
  its error, instead of a text with every number replaced by `#`.
- A notification template with a malformed variable, such as
  `{{ total_all }` with a brace missing, can no longer be saved: such a
  variable was sent to the recipients as it was, without its value.
  Templates already stored are not checked; fix them in the administration.

## [1.4.0] - 2026-11-27

### Added

- The system monitors (`system.monitor`) are notified when the remote
  prediction server stops computing (#51):
  - it does not answer for 10 minutes;
  - its pods cannot start or cannot be created;
  - a component has no ready replica;
  - queued work is not processed for an hour;
  - work runs without a heartbeat.

  A problem is announced after 5 minutes, again when a new kind joins it
  and once a day while it lasts, and its end after two healthy checks.
- The daily system health report has a "Prediction server" check (#51).
- The administration's dashboard shows the prediction server's state,
  problems and components (#51).
- Every notification has its own page with the whole message, as the
  e-mail has it (`/account/notifications/{id}`); a push opens it, a
  signed-out user comes back to it after signing in.
  `/account/notifications` lists the latest 100, and the bell links each
  one (#50).
- This changelog. The Release workflow takes the notes of a release from it
  (#53).

### Changed

- The public `/api/predictions/server-stats` leaves out the cluster and
  per-step activity the prediction server now reports (#51).

### Fixed

- Saving the notification preferences works again (#48).
- After signing in, the user returns to the page they asked for, also from
  the link in a notification e-mail (#48).
- A page opened from an e-mail or a push no longer shows the user signed
  out; the session is renewed while the user is active, "remember me" keeps
  it for 400 days, and a 401 clears a user the backend has signed out (#50).
- A rollout of the prediction server in a namespace at its quota is no
  longer announced as an outage: pods that wait for the quota count only
  while their component is short of replicas and no rollout replaces its
  pods (#53). Telling a rollout apart needs the predictor from
  MolMeDB/MolMem-predictor#2.

### Security

- Login redirect targets can no longer lead off the site
  (`/%09/example.com`, `/.//example.com`) (#48).
- The nginx cache of `/api/structure` and `/api/mol` no longer stores and
  replays `Set-Cookie`, and requests with a remember cookie bypass it (#50).

### Deployment

- Two migrations run at start: the notification template
  `prediction.admin.server_health`, and `configs.value` widened to `text`.
- The full cluster report needs the prediction server from
  MolMeDB/MolMem-predictor#1; with an older one only whether it answers and
  whether its steps move are watched.

## [1.3.0] - 2026-11-26

### Changed

- `canonical_smiles` of structures and prediction structures is RDKit's
  canonical SMILES written in the Kekulé form, which Bingo reads where it
  refused the aromatic form (#49). In the public API most aromatic
  structures get a different value; the fields stay.
- RDKit is pinned to 2026.9.2 (#49).

### Added

- `structures:convert-canonical-smiles` converts the stored records, with a
  dry run, a report of collisions and a rebuild of the Bingo index (#49).

### Fixed

- The remote prediction token is no longer sent to the browser (#46).
- Deleting a category counts assigned records in the trash; rearranging a
  category tree is validated (#46).
- A new structure is computed again by RDKit when it is saved, and refused
  when it is already stored (#46).
- A prediction result stored as a zip archive is unpacked (#46).
- Push notifications are sent without GMP or BCMath, unreadable SMILES are
  skipped in the similarity search, and bcmath is installed in the PHP
  image (#49).

### Security

- Symfony packages are updated to 8.1.12 for the advisories of 2026-11-25.

### Deployment

- No migrations. After deploying, convert the stored SMILES with
  `structures:convert-canonical-smiles` (workers and scheduler stopped,
  dry run first, the reindex at a quiet hour).

## [1.2.0] - 2026-11-24

### Fixed

- RDKit is reached when its address has no scheme; the structure search
  index comparison of the daily report gets 15 minutes (#39).
- A prediction the remote service keeps refusing is failed after five
  refusals (at most 50 a day); a failed prediction without a remote job can
  be requeued (#40).
- The frontend renders pages for the signed-in visitor and no longer signs
  the user out by preloading the logout (#41).
- A prediction whose result is not a COSMO result is given up (#42).
- Uploads of interactions: column mapping, identifiers on a repeated
  import, approval; one transaction at a time writes to the Bingo index of
  structures (#43).
- Administration: category deletion and rearranging, settings, publication
  dates from Europe PMC and CrossRef, the sitemap, method parameters stored
  as jsonb (#44).

### Security

- SQL injection through `sort_by` in the interaction, publication and
  prediction lists is closed; malformed list parameters no longer answer
  with 500 (#43).
- spatie/laravel-permission 8.5, symfony/filesystem 8.1, Node 24.22 in the
  frontend image (#24, #25, #32).

### Deployment

- Three migrations run at start, one of them on the predictions database.

## [1.1.0] - 2026-11-22

### Added

- Errors the application reports are counted, a repeating one is announced
  at once, and a daily system health report goes to `system.monitor` (#19).
- A Dependency audit workflow and weekly Dependabot updates (#22).

### Changed

- A prediction whose result cannot be used is given up after five
  imports (#20).
- Production runs from a deployment directory; the release carries
  `compose.yaml`, `deploy.sh` and `move-to-standalone.sh` (#18). Both
  databases get five minutes to shut down (#21).

### Security

- PHP and npm dependencies updated to versions without known advisories,
  among them Laravel 13.43, Filament 5.12, Livewire 4.4, Symfony 8.1 and
  Next.js 16.4.6 (#22).

## [1.0.0] - 2026-11-21

The first tagged release (#15).

### Added

- Public REST API v1 and the MCP server (`/api/v1`, `/api/v1/mcp`), RDF
  dereferencing, and the nginx that routes every production domain.
- PWA, web push and notification preferences, with batching of frequent
  notifications.
- CI on PostgreSQL with Bingo, frontend checks and build validation of all
  production images; a version tag on `main` publishes the images to GHCR,
  and `deploy.sh` deploys a named version.

### Security

- An audit of the application, with tests for every area: policies of all
  administration resources, upload queue ownership, downloads, prediction
  tokens, push subscriptions, external service clients and roles.

[1.4.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/MolMeDB/MolMeDB/releases/tag/v1.0.0
