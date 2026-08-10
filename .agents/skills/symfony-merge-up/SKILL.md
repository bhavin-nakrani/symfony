---
name: symfony-merge-up
description: >
  Cascade-merge maintained Symfony branches from oldest to newest (e.g.
  6.4 → 7.4 → 8.0 → 8.1). Use when the user says "merge branches",
  "merge up", "cascade merge", "sync branches", or "update branches".
---

# Symfony Branch Cascade Merge

Merges each maintained branch into the next one, from oldest to newest.

## Progress checklist

- [ ] Step 0: Pre-flight checks
- [ ] Step 1: Fetch maintained branches and pull them
- [ ] Step 2: Cascade merge loop

---

## Confirmation rule

Whenever the skill says **"Wait for confirmation"**, treat anything other than an
explicit affirmative as **no**: stop and ask the user how they want to proceed.

---

## Step 0 — Pre-flight checks

```bash
git status --porcelain --untracked-files=no
```

If any output, **stop**:
> "The working tree is not clean. Please commit or stash your changes first."

---

## Step 1 — Fetch maintained branches and pull them

### 1a. Get the branch list

```bash
curl -s https://symfony.com/releases.json
```

Read `maintained_versions`. It is already sorted oldest → newest (e.g.
`["6.4", "7.4", "8.0", "8.1"]`). Store as `BRANCHES`.

### 1b. Pull every branch

For each branch in `BRANCHES`:

```bash
git checkout <branch>
git pull --ff-only origin <branch>
```

Using `--ff-only` ensures local branches haven't diverged from origin. If the
pull fails, **stop** and report the error.

---

## Step 2 — Cascade merge loop

For each consecutive pair `(SOURCE, TARGET)` in `BRANCHES`:

### 2a. Merge

```bash
git checkout <TARGET>
composer up
git merge <SOURCE>
```

Three outcomes are possible:

- **Already up-to-date:** print "✓ `<TARGET>` already up-to-date with `<SOURCE>`"
  and skip to the next pair.
- **Clean merge (no conflicts):** git creates the merge commit automatically.
  Proceed directly to step 2c.
- **Conflicts:** proceed to step 2b.

### 2b. Resolve conflicts (only when git reports conflicts)

List conflicts:

```bash
git diff --name-only --diff-filter=U
```

Read each conflicted file, resolve it, then `git add` it. When all are resolved:

```bash
git commit --no-edit
```

#### Conflict resolution rules

| File pattern | Strategy |
|---|---|
| `CHANGELOG*.md` | Keep entries from both sides; newer branch entries on top |
| Version constants, `composer.json` branch aliases | Keep the TARGET branch value |
| `.github/workflows/*.yml`, CI config | Keep the TARGET value for branch-specific pins. A new job merged from SOURCE may carry SOURCE's `php-version` (its branch minimum); bump it to the TARGET's minimum (see `min_php_requirements` in releases.json) |
| Idiom the TARGET replaced (e.g. `unserialize(serialize())` - a deep-clone helper, logic extracted to a trait, a method/class removed) | Take the TARGET version; the SOURCE change is superseded. `git checkout --ours <file>` then re-apply any security option (e.g. `allowed_classes`) the TARGET's version happens to drop |
| File the TARGET deleted (modify/delete conflict) | Keep it deleted if the TARGET removed the feature (confirm with `git log <TARGET> -- <file>`); the SOURCE edit is moot. `git rm <file>` |
| Test using docblock metadata (`@dataProvider`, `@group legacy`) | Convert to attributes (`#[DataProvider(...)]`, `#[Group(...)]`) when the TARGET runs PHPUnit 10+ (7.4/8.x here); docblock providers are ignored and the test errors with "too few arguments" |
| Code files | Merge logically based on context; when unsure, ask the user |

#### Structural divergence across major versions

A newer major may have removed deprecated classes, attributes, or config formats
(e.g. `TaggedLocator`, XML DI config), raised the minimum PHP version, or refactored
shared logic into a trait or a new utility class. When merging across such a boundary:

- Remove test methods marked `@group legacy` / `#[Group('legacy')]` for deprecations
  the new major dropped, **and** any test/fixture/import that references a removed
  symbol (otherwise it fatals on the TARGET).
- Each major raises the minimum PHP (min PHP per branch in releases.json
  `min_php_requirements`: 6.4=8.1, 7.4=8.2, 8.0=8.4). Code merged from SOURCE that
  branches on or polyfills a PHP below the TARGET's minimum (`\PHP_VERSION_ID < ...`
  guards, or `function_exists()` / `class_exists()` fallbacks for now-always-available
  symbols) is dead on the TARGET and can be collapsed to the modern path. The TARGET
  usually dropped it already, so prefer its version; clean up only where SOURCE's
  old-PHP code lands somewhere the TARGET had not simplified.
- Prefer the TARGET branch's approach for any refactored idiom.
- Fastest sanity check: look at how the *downstream* branch (one already past this
  divergence, e.g. 8.2 while resolving on 8.0) resolved the same files, and match it.

After resolving, show `git diff HEAD~1` (first parent of the merge commit, i.e.
the previous TARGET state) and wait for the user to confirm the resolution looks
correct before proceeding.

### 2c. Run tests for affected components

Extract component, bridge, and bundle names from changed files:

```bash
git diff --name-only HEAD~1..HEAD
```

Paths look like `src/Symfony/{Component,Bridge,Bundle}/<NAME>/...`. Deduplicate,
then run tests for each:

```bash
./phpunit src/Symfony/Component/<NAME>
./phpunit src/Symfony/Bridge/<NAME>
./phpunit src/Symfony/Bundle/<NAME>
```

For files under `src/Symfony/Contracts/`, run the single shared test suite:

```bash
./phpunit src/Symfony/Contracts
```

Ignore files outside these directories (root configs, `.github/`, etc.): they
don't have component-level test suites.

If tests fail or report PHPUnit deprecations (the PHPUnit version may differ
between branches), first check whether the failure is pre-existing: run the same
test on the TARGET branch before the merge (`git stash && git checkout HEAD~1`
or check CI). Only fix failures introduced by the merge:
1. Analyze and fix the code, including any PHPUnit deprecation notices.
2. Commit the fix: `[<ComponentName>] Fix merge conflict resolution`.
3. Re-run failing tests until green and deprecation-free.

Report any pre-existing failures to the user without attempting to fix them.

### 2c-bis. Run the repo's static-analysis / hardening checks

If the repo ships custom static analysis (this one has `.github/sa-tools/` with
PHPStan rules and `check-hardening-tests.php`), the merge carries those rules into
the TARGET, where they now apply to the TARGET's **own** code. Newer-branch code
can trip rules the SOURCE introduced but never had to satisfy. Run them:

```bash
php .github/sa-tools/check-hardening-tests.php
# and the custom PHPStan rules, as wired in .github/workflows/static-analysis.yml
```

Fix the branch-specific gaps (e.g. add `['allowed_classes' => …]` to a bare
`unserialize()`, add an `instanceof \Stringable` guard to a string-property
`__unserialize()`, or add the missing regression test). These gaps are not
*introduced* by the merge, but the merge makes the checks apply — so they must be
green before pushing. Confirm scope with the user before a large hardening pass.

Beware false positives: untracked nested `vendor/` dirs and the local PHP
extension set (a missing extension falls back to a possibly-outdated polyfill) can
produce findings/failures that do not exist on a clean checkout / CI.

### 2d. Ask for confirmation before pushing

Show:

```
Merge: <SOURCE> → <TARGET>
Affected: <component list>
Tests: all passing

Commits since origin/<TARGET>:
git log --oneline origin/<TARGET>..<TARGET>

Ready to push? (yes / no)
```

**Wait for confirmation.** The user may make changes themselves before confirming.

### 2e. Push and continue

```bash
git push origin <TARGET>
```

If the push fails, **stop** and report the error.

Print "✓ `<SOURCE>` → `<TARGET>` done." and continue to the next pair.

---

## Final summary

```
All merges complete:
  6.4 → 7.4  ✓
  7.4 → 8.0  ✓
  8.0 → 8.1  ✓
```

---

## Gotchas

- `CHANGELOG.md` conflicts are the most common; entries must be kept from both
  sides, never dropped.
- A merge can introduce test failures even without conflicts, because behavior
  from the older branch may be incompatible with newer code. Always run tests.
- A **clean (no-conflict) merge still needs verification**, not just a commit:
  auto-merged test metadata (docblock vs attribute), security-guard slot indices,
  and CI version pins can each be wrong even when git reports no conflict.
- Some components have slow test suites. Only run tests for components with
  changed files, not the entire project.

## Error handling

- **Never** force-push or rewrite history.
- **Never** use `--no-verify` on commits.
- **Never** auto-recover from a failed `git push` or `git pull`. Stop and hand
  control back to the user.
- **Never** parallelize the cascade or run branches concurrently (e.g. via subagents): each
  merge depends on the previous one and shares the git working tree. Run strictly oldest to
  newest, one at a time.
