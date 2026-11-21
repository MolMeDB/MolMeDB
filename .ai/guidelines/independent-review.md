# Independent Review of Every Change

Every piece of work (a commit, a fix, a feature, a migration, a CI change) must be verified by an independent agent before it is reported as done or pushed.

- Run at least one and at most two review rounds. Each round is a fresh agent (a subagent with no access to your reasoning) that gets only the goal of the change, the diff or commit range, and the relevant files, never your conclusions.
- The reviewer checks correctness (does the change do what was asked, edge cases, regressions), security (authorization and policies, input validation, mass assignment, injection, secrets, data exposure in API responses) and tests (the change is covered and the affected tests pass).
- Round 1 findings are fixed, then round 2 reviews the fixed state. If round 2 still finds problems, do not start a third round: fix what is clear and report the rest to the user as open findings.
- A reviewer reports findings; it does not rewrite the change itself.
- In the final report, state how many rounds ran, what each round found and what was fixed or left open.
