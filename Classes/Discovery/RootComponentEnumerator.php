<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Discovery;

use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Utility\ComponentRootUtility;

/**
 * Lists every root component a collection knows about. Nothing in fluid-primitives itself
 * maintains such a list (`getComponentDefinition()` requires a name up front), so this walks the
 * collection's own template root paths recursively - a candidate viewHelperName per template file
 * found at any depth, dot-joined from its own containing folders (mirroring how Fluid itself
 * resolves a dotted tag name to a `/`-joined template path) - and asks
 * {@see ComponentRootUtility::isDeclaredRootFromViewHelperName()} which of those are actually
 * root, the same folder-shape rule (single-file component, literally `Root`, or a Root-less
 * folder making every file in it independently root) real rendering uses. This is what makes a
 * tiered component (`Molecules/CheckboxGroup/Root.html`) or an independently-root leaf nested one
 * level under a real component's own sibling folder (`CheckboxGroupExamples/SelectAll.html`)
 * discoverable at all - a plain top-level-only scan would only ever find `Molecules`/
 * `CheckboxGroupExamples` themselves, never the real files one or more levels below them.
 *
 * Deduplicates by viewHelperName across multiple template root paths: `TemplatePaths` treats
 * several root paths as a fallback chain (the first one that has a given template wins, e.g.
 * the docs site's own collection registers both a "ui" wrapper tree and a plain "Components"
 * tree), not independent sets to enumerate separately - scanning each path unconditionally would
 * otherwise report the same component more than once whenever a later root path happens to have
 * a same-named directory that's actually unreachable/shadowed for that name.
 */
final class RootComponentEnumerator
{
    /**
     * @return list<string> root viewHelperNames, e.g. "select.root", "clipboard",
     *     "molecules.checkboxGroup.root" or "checkboxGroupExamples.selectAll"
     */
    public function enumerate(AbstractComponentCollection $collection): array
    {
        /** @var array<string, true> $viewHelperNames */
        $viewHelperNames = [];

        $rootPaths = $collection->getTemplatePaths()->getTemplateRootPaths();

        // A collection can register a subfolder of one root path as its own, separate, more
        // specific root path too (e.g. "Components/ui/" alongside "Components/", so <ui:button>
        // works without an extra prefix - see the "Template Path Configuration" docs) - walking
        // into "ui/" again while already inside "Components/" would re-discover everything in it
        // under a spurious leading "ui." segment that's never actually part of its real dotted
        // name, since "ui/" is reached directly, as its own root path, moments later.
        /** @var list<string> $otherRootPaths */
        $otherRootPaths = array_values(array_filter(
            array_map(static fn(string $path): string|false => realpath(rtrim($path, characters: '/')), $rootPaths),
            static fn(string|false $path): bool => $path !== false,
        ));

        foreach ($rootPaths as $rootPath) {
            $rootPath = rtrim($rootPath, characters: '/');
            if (!is_dir($rootPath)) {
                continue;
            }

            foreach ($this->findTemplateNames($rootPath, '', '', $otherRootPaths) as $candidate) {
                if (isset($viewHelperNames[$candidate])) {
                    continue;
                }

                if (!ComponentRootUtility::isDeclaredRootFromViewHelperName($candidate, $collection)) {
                    continue;
                }

                // A candidate built from a nested single-file component (its own file name
                // matching its immediate folder, one or more levels deep, e.g.
                // "Vanilla/Input/Input.html") is only real if Fluid's own resolver actually
                // recognizes the collapsed form - true for a top-level single-file component
                // (Fluid's controller/action fallback tries "$name/$name.html" for a bare
                // candidate), not guaranteed at arbitrary depth. Verifying existence here, the
                // same way the pre-recursion version of this enumerator always did, is simpler
                // than modeling that resolution rule ourselves - an unresolvable candidate is
                // silently skipped rather than crashing resolveComponent() later.
                $templateName = $collection->resolveTemplateName($candidate);
                if (!$collection->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
                    'Default',
                    $templateName,
                )) {
                    continue;
                }

                $viewHelperNames[$candidate] = true;
            }
        }

        return array_keys($viewHelperNames);
    }

    /**
     * Every candidate viewHelperName for a template file at or below `$directory` - root and
     * non-root alike, since `isDeclaredRootFromViewHelperName()` (called by the caller, per
     * candidate) needs to see a subcomponent sibling to correctly decide its own root file isn't
     * independently root by the folder-shape default.
     *
     * `$directoryOwnName` is `$directory`'s own basename (empty at the top-level call, where
     * there's no component folder yet, only the collection's registered template root itself) -
     * a file whose own name (before its first extension dot) matches it is a single-file
     * component (`Button/Button.html`), addressed as the bare directory name with no repeated
     * segment appended, exactly like {@see \Jramke\FluidPrimitives\Utility\ComponentNameUtility::getRootViewHelperNameCandidates()}'s
     * own single-file candidate.
     *
     * @param list<string> $otherRootPaths
     * @return list<string>
     */
    private function findTemplateNames(
        string $directory,
        string $viewHelperPrefix,
        string $directoryOwnName,
        array $otherRootPaths,
    ): array {
        $names = [];
        $subdirectories = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $directory . '/' . $entry;
            if (is_dir($entryPath)) {
                $realEntryPath = realpath($entryPath);
                if ($realEntryPath !== false && in_array($realEntryPath, $otherRootPaths, strict: true)) {
                    continue;
                }
                $subdirectories[] = $entry;
                continue;
            }

            // Only template files are candidates - a component's own *.entry.ts/*.stories.ts
            // sitting next to its templates (often named after the component itself) is not one,
            // and must not be mistaken for a single-file component matching its own folder name.
            if (!str_ends_with($entry, '.html')) {
                continue;
            }

            $fileName = explode('.', $entry)[0];
            $names[] = $fileName === $directoryOwnName
                ? rtrim($viewHelperPrefix, characters: '.')
                : $viewHelperPrefix . $fileName;
        }

        foreach ($subdirectories as $subdirectory) {
            $names = [
                ...$names,
                ...$this->findTemplateNames(
                    $directory . '/' . $subdirectory,
                    $viewHelperPrefix . $subdirectory . '.',
                    $subdirectory,
                    $otherRootPaths,
                ),
            ];
        }

        return $names;
    }
}
