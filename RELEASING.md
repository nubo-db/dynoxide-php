# Releasing

The package is versioned in step with Dynoxide, so a release is one of two
kinds:

- **Following a Dynoxide release.** Version X.Y.Z of this package runs
  Dynoxide X.Y.Z and carries that release's checksums.
- **A fix to the package alone.** It adds a fourth part, X.Y.Z.N, and still
  runs Dynoxide X.Y.Z.

Packagist publishes a tag as soon as it's pushed, and the repository's tag
rulesets let only admins create a tag and stop anyone moving or deleting one.
So everything is checked before the tag goes up, and a mistake is fixed with a
new version, not a retag.

## Following a Dynoxide release

1. Wait until the Dynoxide release vX.Y.Z has its GitHub Release, with its
   archives and `sha256sums.txt`.
2. Make a branch from `main` and run:

   ```sh
   php scripts/update-release.php vX.Y.Z
   ```

   It downloads `sha256sums.txt` and every archive, checks each archive
   against it, unpacks it with the package's own readers, and rewrites
   `src/Release.php`. A change to how Dynoxide packages its releases fails
   here, before anything is tagged.
3. In `README.md`, move the versions in "version X.Y.Z of this package runs
   Dynoxide X.Y.Z", in the fourth-part example after it, and in the
   `$binary->version` example.
4. In `CHANGELOG.md`, move the versions in the opening sentence, put the
   Unreleased entries under `## [X.Y.Z] - YYYY-MM-DD`, and add "**Runs
   Dynoxide X.Y.Z**, up from the previous version, with that release's
   checksums." under Changed.
5. Commit as `release: X.Y.Z, running Dynoxide X.Y.Z`, open a pull request,
   and merge it once CI is green.
6. Run the release check on the merge commit, while it's still the tip of
   `main`:

   ```sh
   gh workflow run release.yml -f tag=vX.Y.Z -f sha=<full SHA of the merge commit>
   ```

   It checks that the tag matches `src/Release.php` and the README, then runs
   every CI leg against the release's own binaries. It publishes nothing.
7. Once it's green, tag that commit and push the tag:

   ```sh
   git tag -s vX.Y.Z -m vX.Y.Z <full SHA>
   git push origin vX.Y.Z
   ```

   The push runs the release check again, as a second look.
8. Check that Packagist lists the version:
   `composer show --all nubo-db/dynoxide`.

## A fix to the package alone

The same steps, with these differences:

- Skip step 2: `src/Release.php` stays on Dynoxide X.Y.Z.
- Leave the README's versions alone, because the release check holds them to
  the embedded Dynoxide version.
- The CHANGELOG heading is `## [X.Y.Z.N] - YYYY-MM-DD`, opening with "Still
  runs Dynoxide X.Y.Z."
- The tag is vX.Y.Z.N.

## After a release

The Laravel package, `nubo-db/dynoxide-laravel`, requires this one with a caret
constraint, so it picks up the new version without a release of its own. Its
`RELEASING.md` says how to check that.
