# Independent Review of Every Change

Every piece of work (a commit, a fix, a feature, a migration, a CI change) must be verified by an independent agent before it is reported as done or pushed.

- Run one review round by default. Each round is a fresh agent (a subagent with no access to your reasoning) that gets only the goal of the change, the diff or commit range, and the relevant files, never your conclusions.
- Run a second round only as an exception: when the first round found many problems or serious ones (high or critical) whose fixes changed the solution substantially. Never run more than two rounds.
- The reviewer checks correctness (does the change do what was asked, edge cases, regressions), security (authorization and policies, input validation, mass assignment, injection, secrets, data exposure in API responses) and tests (the change is covered and the affected tests pass).
- Fix the round 1 findings and test the fixes. If a second round runs, it reviews the fixed state; whatever it still finds, fix what is clear and report the rest to the user as open findings.
- A reviewer reports findings; it does not rewrite the change itself.
- In the final report, state how many rounds ran, what each round found and what was fixed or left open.
