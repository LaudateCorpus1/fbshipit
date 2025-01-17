<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/j2zf8wd1
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{C, Regex, Str, Vec}; // @oss-enable

class ShipItException extends \Exception {}

final class ShipItImportDisallowedException extends ShipItException {}

final class ShipItSync {
  public function __construct(
    private ShipItManifest $manifest,
    private ShipItSyncConfig $syncConfig,
  ) {
  }

  private async function genFirstSourceID(): Awaitable<?string> {
    $config = $this->syncConfig;
    $rev = $config->getFirstCommit();
    if ($rev === null) {
      $src = await $this->genRepo(ShipItSourceRepo::class);

      $last_synced_commit = await $this->genFindLastSyncedCommit();
      $rev = await $src->genFindNextCommit(
        $last_synced_commit,
        $config->getSourceRoots(),
      );
    }
    return $rev;
  }

  private async function genSourceChangesets(
  ): Awaitable<vec<ShipItChangeset>> {
    $config = $this->syncConfig;
    $src = await $this->genRepo(ShipItSourceRepo::class);

    $changesets = vec[];
    $rev = await $this->genFirstSourceID();
    while ($rev !== null) {
      // @lint-ignore AWAIT_IN_LOOP We need to do this serially
      $changeset = await $src->genChangesetFromID($rev);

      if (!$changeset) {
        throw new ShipItException("Unable to get patch for $rev");
      }

      $changesets[] = $changeset;
      // @lint-ignore AWAIT_IN_LOOP We need to do this serially
      $rev = await $src->genFindNextCommit($rev, $config->getSourceRoots());
    }
    return $changesets;
  }

  private async function genFilteredChangesets(
  ): Awaitable<vec<ShipItChangeset>> {
    $manifest = $this->manifest;
    $skipped_ids = $this->syncConfig->getSkippedSourceCommits();
    $gen_filter = $this->syncConfig->getFilter();

    $source_changesets = await $this->genSourceChangesets();
    return await Vec\map_async(
      $source_changesets,
      async $changeset ==> {
        $skip_match = null;
        foreach ($skipped_ids as $skip_id) {
          if (Str\search($changeset->getID(), $skip_id) === 0) {
            $skip_match = $skip_id;
            break;
          }
        }
        if ($skip_match !== null) {
          return $changeset
            ->withDiffs(vec[])
            ->withDebugMessage(
              'USER SKIPPED COMMIT: id "%s" matches "%s"',
              $changeset->getID(),
              $skip_match,
            );
        }

        $changeset = await $gen_filter($manifest, $changeset);
        if (!$this->isValidChangeToSync($changeset)) {
          return $changeset->withDebugMessage(
            'SKIPPED COMMIT: no matching files',
          );
        } else {
          return self::addTrackingData($manifest, $changeset);
        }
      },
    );
  }

  public async function genRun(): Awaitable<void> {
    $changesets = await $this->genFilteredChangesets();
    if (C\is_empty($changesets)) {
      ShipItLogger::out("  No new commits to sync.\n");
      await $this->genMaybeLogStats(vec[], vec[]);
      return;
    }

    $patches_dir = $this->syncConfig->getPatchesDirectory();
    if ($patches_dir !== null && !PHP\file_exists($patches_dir)) {
      PHP\mkdir($patches_dir, 0755, /* recursive = */ true);
    }

    $verbose = $this->manifest->isVerboseEnabled();
    $dest = await $this->genRepo(ShipItDestinationRepo::class);

    $changesets = await $this->syncConfig
      ->genPostFilterChangesets($changesets, $dest);

    $changesets_applied = vec[];
    $changesets_skipped = vec[];
    foreach ($changesets as $changeset) {
      if ($patches_dir !== null) {
        $file = $patches_dir.
          '/'.
          $this->manifest->getDestinationBranch().
          '-'.
          $changeset->getID().
          '.patch';
        if (PHP\file_exists($file)) {
          ShipItLogger::out("Overwriting patch file: %s\n", $file);
        }
        PHP\file_put_contents($file, $dest::renderPatch($changeset));
        $changeset = $changeset->withDebugMessage(
          'Saved patch file: %s',
          $file,
        );
      }

      if ($verbose) {
        $changeset->dumpDebugMessages();
      }

      if (!$this->isValidChangeToSync($changeset)) {
        ShipItLogger::out(
          "  SKIP %s %s\n",
          $changeset->getShortID(),
          $changeset->getSubject(),
        );
        $changesets_skipped[] = $changeset;
        continue;
      }

      try {
        // @lint-ignore AWAIT_IN_LOOP These need to be committed one at a time
        await $dest->genCommitPatch(
          $changeset,
          $this->syncConfig->getShouldDoSubmodules(),
        );
        ShipItLogger::out(
          "  OK %s %s\n",
          $changeset->getShortID(),
          $changeset->getSubject(),
        );
        $changesets_applied[] = $changeset;
        continue;
      } catch (ShipItRepoException $e) {
        ShipItLogger::err(
          "Failed to apply patch %s (%s): %s\n",
          $changeset->getID(),
          $changeset->getMessage(),
          $e->getMessage(),
        );
        throw $e;
      } catch (\Exception $e) {
        ShipItLogger::err(
          "  !! %s ERROR: sync failed:\n",
          $changeset->getShortID(),
        );
        if ($verbose) {
          ShipItLogger::err("%s\n", ShipItRepoGIT::renderPatch($changeset));
        }
        throw $e;
      }
    }

    await $this->genMaybeLogStats($changesets_applied, $changesets_skipped);
  }

  /**
   * Optionally logs stats about the sync to the user-specified file.
   *
   * @param $changesets_applied the changesets that were applied.
   */
  private async function genMaybeLogStats(
    vec<ShipItChangeset> $changesets_applied,
    vec<ShipItChangeset> $changesets_skipped,
  ): Awaitable<void> {
    $filename = $this->syncConfig->getStatsFilename();
    if ($filename === null) {
      return;
    }
    $destination_branch = $this->manifest->getDestinationBranch();
    // Support logging stats for a project with multiple branches.
    if (PHP\is_dir($filename)) {
      // Slashes are allowed in branch names but not filenames.
      $namesafe_branch = Regex\replace(
        $destination_branch,
        re"/[^a-zA-Z0-9_\-.]/",
        '_',
      );
      $filename = $filename.'/'.$namesafe_branch.'.json';
    }
    $source_repo = await $this->genRepo(ShipItSourceRepo::class);
    $source_changeset = await $source_repo->genHeadChangeset();
    $destination_repo = await $this->genRepo(ShipItDestinationRepo::class);
    $destination_changeset = await $destination_repo
      ->genHeadChangeset();
    PHP\file_put_contents(
      $filename,
      \json_encode(dict[
        'source' => dict[
          'id' => $source_changeset?->getID(),
          'timestamp' => $source_changeset?->getTimestamp(),
          'branch' => $this->manifest->getSourceBranch(),
        ],
        'destination' => dict[
          'id' => $destination_changeset?->getID(),
          'timestamp' => $destination_changeset?->getTimestamp(),
          'branch' => $destination_branch,
        ],
        'changesets' =>
          Vec\map($changesets_applied, $changeset ==> $changeset->getID()),
        'skipped' =>
          Vec\map($changesets_skipped, $changeset ==> $changeset->getID()),
      ]),
    );
  }

  /** Sync the change ONLY if:
  *  ChangeSet is not Empty OR
  *  ChangeSet isTaggedEmpty and the project allows empty commit.
  */
  private function isValidChangeToSync(ShipItChangeset $changeset): bool {
    $is_empty = $changeset->isEmptyChange();
    $is_tagged_empty_commit = $changeset->getIsTaggedEmptyCommit();
    return (
      !$is_empty ||
      ($this->syncConfig->getAllowEmptyCommits() && $is_tagged_empty_commit)
    );
  }

  private static function checkLastRev(?string $diff): string {
    if ($diff === null) {
      throw new ShipItException(
        "Unable to determine last differential revision pushed to dest repo",
      );
    }
    if (!Regex\matches($diff, re"/^D[0-9]{6,}$/")) {
      throw new ShipItException(
        "Last differential revision number ('{$diff}') is invalid",
      );
    }
    return $diff;
  }

  private static function checkFindDiff(?string $id, string $diff): string {
    if ($id === null) {
      throw new ShipItException("Unable to find $diff in source repo");
    }
    return $id;
  }

  <<__Memoize>>
  private async function genRepo<Trepo as ShipItRepo>(
    classname<Trepo> $class,
  ): Awaitable<Trepo> {
    $manifest = $this->manifest;

    if ($class === ShipItSourceRepo::class) {
      return await ShipItRepo::genTypedOpen(
        $class,
        $manifest->getSourceSharedLock(),
        $manifest->getSourcePath(),
        $manifest->getSourceBranch(),
      );
    }

    if ($class === ShipItDestinationRepo::class) {
      return await ShipItRepo::genTypedOpen(
        $class,
        $manifest->getDestinationSharedLock(),
        $manifest->getDestinationPath(),
        $manifest->getDestinationBranch(),
      );
    }

    invariant_violation(
      'Got class %s, expected %s or %s',
      $class,
      ShipItSourceRepo::class,
      ShipItDestinationRepo::class,
    );
  }

  private async function genFindLastSyncedCommit(): Awaitable<string> {
    $dest = await $this->genRepo(ShipItDestinationRepo::class);

    $src_commit = await $dest->genFindLastSourceCommit(
      $this->syncConfig->getDestinationRoots(),
    );
    if ($src_commit === null) {
      throw new ShipItException("Couldn't find synced commit id");
    }
    return $src_commit;
  }

  public static function addTrackingData(
    ShipItManifest $manifest,
    ShipItChangeset $changeset,
    ?string $rev = null,
  ): ShipItChangeset {
    if ($rev === null) {
      $rev = $changeset->getID();
    }
    $new_message = Str\format(
      "%s\n\n%sshipit-source-id: %s",
      $changeset->getMessage(),
      $manifest->getCommitMarkerPrefix() ? 'fb' : '',
      $rev,
    );
    // Co-authored-by must be the absolute last thing in the message
    $co_author_lines = $changeset->getCoAuthorLines();
    if (Str\length($co_author_lines) > 0) {
      $new_message .= "\n\n".$co_author_lines;
    }
    return $changeset->withMessage(Str\trim($new_message));
  }

  public static function getTrackingDataFromString(
    string $raw_changeset,
  ): ?string {
    $matches = Regex\every_match(
      $raw_changeset,
      re"/^ *(fb)?shipit-source-id: ?(?<commit>[a-z0-9]+)$/m",
    );
    $last_match = C\last($matches);
    if ($last_match is null) {
      return null;
    }
    return $last_match['commit'];
  }
}
